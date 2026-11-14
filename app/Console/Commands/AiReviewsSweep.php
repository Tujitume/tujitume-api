<?php

namespace App\Console\Commands;

use App\Jobs\RunApplicationAiReviewJob;
use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Service\AiReview\AiReviewDispatcher;
use App\Service\AiReview\AiReviewNotifier;
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
            $submitted = ProgramApplication::where('current_round_id', $round->id)->whereIn('round_status', ['submitted', 'scored'])->get();

            // 1. Pick up anything that has no review yet, is stale, queued or failed (within the attempt limit)
            foreach ($submitted as $application) {
                $review = $dispatcher->dispatchFor($application, $round);
                if ($review && in_array($review->status, [ApplicationAiReview::STATUS_QUEUED], true)) {
                    RunApplicationAiReviewJob::dispatch($review->id);
                }
                if ($review && $review->status === ApplicationAiReview::STATUS_FAILED && $review->attempts < (int) config('ai_review.retries')) {
                    $review->update(['status' => ApplicationAiReview::STATUS_QUEUED]);
                    RunApplicationAiReviewJob::dispatch($review->id);
                }
            }

            $reviews = ApplicationAiReview::where('round_id', $round->id)->where('status', '!=', 'stale')->get()->keyBy('application_id');
            $inFlight = $submitted->contains(fn ($a) => in_array($reviews[$a->id]->status ?? 'queued', ['queued', 'extracting', 'analyzing'], true));
            $allReady = $submitted->isNotEmpty() && $submitted->every(fn ($a) => ($reviews[$a->id]->status ?? null) === 'ready');

            // 2. Assisted: flush batched "reports ready" notices, and tell the owner once when everything is ready
            if ($round->review_method === 'ai_assisted') {
                $reviewerIds = ReviewerApplicationAssignment::where('round_id', $round->id)->distinct()->pluck('reviewer_id');
                foreach ($reviewerIds as $id) $notifier->flushReviewer($round, (int) $id);
                if ($allReady && ! $round->ai_review_notified_at) {
                    // Only the owner who scores the round themselves is told. When Business Coaches or team members review, the
                    // reports-ready notice already goes to them above, and the owner has nothing to review.
                    if ($round->assignment_type === 'owner_only' && ($owner = $notifier->owner($round))) {
                        (new \App\Service\Notification\ProgramNotificationService())->send('ai_review.reports_ready', [$owner], ['program_id' => $round->program_id, 'round_id' => $round->id, 'program_title' => $round->program?->program_title, 'round_name' => $round->round_name, 'count' => $submitted->count()]);
                    }
                    $round->update(['ai_review_notified_at' => now()]);
                }
            }

            // 3. AI-only: once the review period has passed and nothing is still running, tell the owner ONCE
            if ($round->isAiOnly() && ! $round->ai_review_notified_at) {
                $end = $round->review_period_end ?? $round->close_date;
                if ($end && now()->startOfDay()->gt($end->copy()->startOfDay()) && ! $inFlight && $submitted->isNotEmpty()) {
                    $problems = $submitted->filter(fn ($a) => ! in_array($a->round_status, ['scored'], true) || ($reviews[$a->id]->status ?? null) !== 'ready')->map(fn ($a) => ($a->startup_name ?: 'Application #'.$a->id))->values();
                    $round->update(['ai_review_notified_at' => now()]);
                    $this->notifyScored($notifier, $round, $problems->all());
                }
            }

            // 4. Capacity watch: will the provider get through what is left before the review period ends?
            if (! $round->ai_capacity_notified_at) {
                $pending = $submitted->filter(fn ($a) => ($reviews[$a->id]->status ?? null) !== 'ready')->count();
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
