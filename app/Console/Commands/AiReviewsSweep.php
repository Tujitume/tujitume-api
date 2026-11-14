<?php

namespace App\Console\Commands;

use App\Jobs\RunApplicationAiReviewJob;
use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Service\AiReview\AiReviewDispatcher;
use App\Service\AiReview\AiReviewNotifier;
use App\Service\AiReview\BackgroundWork;
use App\Service\AiReview\RateGovernor;
use Illuminate\Console\Command;

class AiReviewsSweep extends Command
{
    protected $signature = 'ai-reviews:sweep';
    protected $description = 'Retry queued/failed AI reviews, notify owners and reviewers, and watch provider capacity (hourly, idempotent).';

    public function handle(AiReviewDispatcher $dispatcher, AiReviewNotifier $notifier, RateGovernor $governor): int
    {
        if (! config('ai_review.enabled')) return self::SUCCESS;

        $rounds = ProgramRound::whereIn('review_method', ['ai_assisted', 'ai_only'])->whereNotIn('status', ['draft', 'finalized'])->get();

        foreach ($rounds as $round) {
            if ($round->ai_review_paused_at) continue; // stopped by the program owner

            // Everything below is counted in the database and handled in small batches, so one round with a very large number of
            // applications (or many rounds at once) never loads them all into memory.
            $submittedQuery = fn () => ProgramApplication::where('current_round_id', $round->id)->whereIn('round_status', ProgramApplication::AI_REVIEWABLE_STATUSES)->notAutomated();
            $latestIds = fn () => ApplicationAiReview::query()->where('round_id', $round->id)->where('status', '!=', 'stale')
                ->whereIn('application_id', $submittedQuery()->select('id'))->selectRaw('MAX(id)')->groupBy('application_id');
            $byStatus = ApplicationAiReview::whereIn('id', $latestIds())->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
            $submitted = $submittedQuery()->count();
            $ready = (int) ($byStatus['ready'] ?? 0);
            $queued = (int) ($byStatus['queued'] ?? 0);
            $reviewed = (int) $byStatus->sum();
            $unreviewed = max(0, $submitted - $reviewed);
            $inFlight = $unreviewed + $queued + (int) ($byStatus['extracting'] ?? 0) + (int) ($byStatus['analyzing'] ?? 0) > 0;
            $allReady = $submitted > 0 && $ready >= $submitted;
            $pending = max(0, $submitted - $ready);

            // 1. Carry on with whatever is waiting, whoever is (or is not) looking at the page
            if ($unreviewed + $queued > 0) {
                if (BackgroundWork::needed()) {
                    // No queue worker: one background process per round works through the waiting ones, one after another
                    BackgroundWork::start($round);
                } else {
                    // A batch at a time; the next sweep takes the next batch
                    ApplicationAiReview::where('round_id', $round->id)->where('status', ApplicationAiReview::STATUS_QUEUED)->whereIn('application_id', $submittedQuery()->select('id'))
                        ->orderBy('id')->limit(500)->pluck('id')->each(fn ($id) => RunApplicationAiReviewJob::dispatch($id));
                    $submittedQuery()->whereNotIn('id', ApplicationAiReview::where('round_id', $round->id)->where('status', '!=', ApplicationAiReview::STATUS_STALE)->select('application_id'))
                        ->orderBy('id')->limit(500)->get()->each(fn ($application) => $dispatcher->dispatchFor($application, $round));
                }
            }
            // Ones that failed (provider trouble) are tried again, within the attempt limit
            ApplicationAiReview::whereIn('id', $latestIds())->where('status', ApplicationAiReview::STATUS_FAILED)->where('attempts', '<', (int) config('ai_review.retries'))
                ->limit(200)->get()->each(function ($review) {
                    $review->update(['status' => ApplicationAiReview::STATUS_QUEUED]);
                    if (! BackgroundWork::needed()) RunApplicationAiReviewJob::dispatch($review->id);
                });

            // 2. Assisted: flush batched "reports ready" notices, and tell the owner once when everything is ready
            if ($round->review_method === 'ai_assisted') {
                // The owner is included: applications with nobody assigned are reported to them
                $reviewerIds = ReviewerApplicationAssignment::where('round_id', $round->id)->distinct()->pluck('reviewer_id')->push($notifier->owner($round)?->id)->filter()->unique();
                foreach ($reviewerIds as $id) $notifier->flushReviewer($round, (int) $id);
                if ($allReady && ! $round->ai_review_notified_at) {
                    // Only the owner who scores the round themselves is told. When Business Coaches or team members review, the
                    // reports-ready notice already goes to them above, and the owner has nothing to review.
                    if ($round->assignment_type === 'owner_only' && ($owner = $notifier->owner($round))) {
                        (new \App\Service\Notification\ProgramNotificationService())->send('ai_review.reports_ready', [$owner], ['program_id' => $round->program_id, 'round_id' => $round->id, 'program_title' => $round->program?->program_title, 'round_name' => $round->round_name, 'count' => $submitted]);
                    }
                    $round->update(['ai_review_notified_at' => now()]);
                }
            }

            // 3. AI-only: once the review period has passed and nothing is still running, tell the owner ONCE
            if ($round->isAiOnly() && ! $round->ai_review_notified_at) {
                $end = $round->review_period_end ?? $round->close_date;
                if ($end && now()->startOfDay()->gt($end->copy()->startOfDay()) && ! $inFlight && $submitted > 0) {
                    $problems = $submittedQuery()->where(function ($q) use ($latestIds) {
                        $q->where('round_status', '!=', 'scored')->orWhereNotIn('id', ApplicationAiReview::whereIn('id', $latestIds())->where('status', 'ready')->select('application_id'));
                    })->limit(20)->get(['id', 'startup_name'])->map(fn ($a) => $a->startup_name ?: 'Application #'.$a->id);
                    $round->update(['ai_review_notified_at' => now()]);
                    $this->notifyScored($notifier, $round, $problems->all());
                }
            }

            // 4. Capacity watch: will the provider get through what is left before the review period ends?
            if (! $round->ai_capacity_notified_at) {
                $end = $round->review_period_end ?? $round->close_date;
                if ($pending > 0 && $end && now()->lt($end)) {
                    $provider = (string) config('ai_review.default_provider');
                    $cap = $governor->capacity($provider);
                    $days = max(1, now()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1);
                    $available = $cap['requests_remaining_today'] + $cap['requests_per_day'] * ($days - 1);
                    if ($pending * 2 > $available) {
                        $round->update(['ai_capacity_notified_at' => now()]);
                        $notifier->attention($round, "About {$pending} applications are waiting but the AI provider ({$provider}) has capacity for roughly ".(int) floor($available / 2).' before the review period ends. Use another provider or plan, or score some applications manually.');
                    }
                }
            }
        }

        return self::SUCCESS;
    }

    private function notifyScored(AiReviewNotifier $notifier, ProgramRound $round, array $problems): void
    {
        if ($problems) {
            $notifier->attention($round, 'The round has been scored by AI, but these applications need manual scoring: '.implode(', ', array_slice($problems, 0, 20)).'. Please finalize after scoring them.');

            return;
        }
        $notifier->roundScored($round);
    }
}
