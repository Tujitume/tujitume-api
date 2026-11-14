<?php

namespace App\Console\Commands;

use App\Jobs\RunApplicationAiReviewJob;
use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Service\AiReview\AiReviewDispatcher;
use App\Service\AiReview\RateGovernor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Works through one round's waiting AI reviews, one application after another, until none is left or the owner stops it.
 * It is what "Continue" starts in the background when the server has no queue worker, so the page does not have to keep asking.
 * It waits out the provider's limits instead of failing, and it checks for a stop before every application.
 */
class AiReviewsWork extends Command
{
    protected $signature = 'ai-reviews:work {round : The round id}';
    protected $description = 'Review the waiting applications of a round one by one until done or stopped.';

    public function handle(AiReviewDispatcher $dispatcher, RateGovernor $governor): int
    {
        $round = ProgramRound::find($this->argument('round'));
        if (! $round || ! $round->usesAi() || ! config('ai_review.enabled')) return self::FAILURE;

        // One worker per round: a second Continue while this runs simply ends
        $lock = Cache::lock("ai:work:{$round->id}", 6 * 3600);
        if (! $lock->get()) return self::SUCCESS;

        try {
            // An application that cannot be finished is skipped (left as it is for a person) and the next one is taken, so one bad
            // application never stops the rest.
            $skip = [];
            $stuck = [];
            for ($i = 0; $i < 100000; $i++) {
                $round->refresh();
                if ($round->ai_review_paused_at || $round->status === 'finalized') break;

                $review = $this->next($round, $dispatcher, $skip);
                if (! $review) break; // nothing left

                try {
                    RunApplicationAiReviewJob::dispatchSync($review->id);
                } catch (\Throwable $e) {
                    \Log::warning('AI review skipped after an error', ['review_id' => $review->id, 'class' => get_class($e)]);
                    $review->refresh();
                    if ($review->status === ApplicationAiReview::STATUS_QUEUED) {
                        $review->update(['status' => ApplicationAiReview::STATUS_FAILED, 'error' => 'The AI review hit an unexpected error.', 'completed_at' => now()]);
                    }
                    $skip[] = $review->id;
                    continue;
                }

                // The provider asked for a pause (its limit): the review stays queued. Wait it out, then carry on; one that keeps coming back is skipped.
                $review->refresh();
                if ($review->status === ApplicationAiReview::STATUS_QUEUED) {
                    $stuck[$review->id] = ($stuck[$review->id] ?? 0) + 1;
                    if ($stuck[$review->id] >= 30) {
                        $skip[] = $review->id;
                        continue;
                    }
                    sleep(max(5, min(120, $governor->cooldownRemaining((string) config('ai_review.default_provider')) ?: 15)));
                }
            }
        } finally {
            $lock->release();
        }

        // An application can arrive in the moment between "nothing left" and letting go of the lock (its own request found the lock held and
        // did not start another worker). Look once more now, and carry on if something came in.
        $round->refresh();
        if (! $round->ai_review_paused_at && $this->next($round, $dispatcher, [])) {
            return $this->handle($dispatcher, $governor);
        }

        return self::SUCCESS;
    }

    /** The oldest waiting review, else a submitted application that has none yet (its review is created now). */
    private function next(ProgramRound $round, AiReviewDispatcher $dispatcher, array $skip = []): ?ApplicationAiReview
    {
        $queued = ApplicationAiReview::where('round_id', $round->id)->where('status', ApplicationAiReview::STATUS_QUEUED)->whereNotIn('id', $skip)
            ->whereIn('application_id', ProgramApplication::where('current_round_id', $round->id)->whereIn('round_status', ProgramApplication::AI_REVIEWABLE_STATUSES)->notAutomated()->select('id'))
            ->orderBy('updated_at')->orderBy('id')->first();
        if ($queued) return $queued;

        $application = ProgramApplication::where('current_round_id', $round->id)->whereIn('round_status', ProgramApplication::AI_REVIEWABLE_STATUSES)->notAutomated()
            ->whereNotIn('id', ApplicationAiReview::where('round_id', $round->id)->where('status', '!=', ApplicationAiReview::STATUS_STALE)->select('application_id'))
            ->orderBy('id')->first();
        if (! $application) return null;

        // This command is the worker: the review row is only created here and the loop above runs it itself
        return $dispatcher->dispatchFor($application, $round, false, false);
    }
}
