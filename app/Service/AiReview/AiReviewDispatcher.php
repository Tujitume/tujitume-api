<?php

namespace App\Service\AiReview;

use App\Jobs\RunApplicationAiReviewJob;
use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;

/** Creates (or reuses) the review row for an application's current content and queues the job. Idempotent by input hash. */
class AiReviewDispatcher
{
    public function __construct(private ReviewInputBuilder $inputs)
    {
    }

    /** Best effort and never part of the applicant's request failing: queue a review for an application that was just submitted. */
    public static function safely(?ProgramApplication $application, bool $onlyIfSubmitted = false): void
    {
        try {
            if (! $application || ! config('ai_review.enabled')) return;
            if ($onlyIfSubmitted && ! in_array($application->fresh()->round_status, ['submitted', 'scored'], true)) return;
            app(self::class)->dispatchFor($application->fresh());
        } catch (\Throwable $e) {
            \Log::warning('AI review could not be queued', ['application_id' => $application?->id, 'class' => get_class($e)]);
        }
    }

    public function dispatchFor(ProgramApplication $application, ?ProgramRound $round = null, bool $force = false, bool $run = true): ?ApplicationAiReview
    {
        $round ??= $application->currentRound;
        if (! $round || ! $round->usesAi() || ! config('ai_review.enabled')) return null;
        if (in_array($round->status, ['finalized'], true)) return null;
        // Applications made by the test-data generator are never sent to the AI: nobody needs them scored and each one would cost tokens
        if (! \App\Service\Misc\AutomatedAccounts::$reviewWithAi && \App\Service\Misc\AutomatedAccounts::forApplication($application)) return null;

        $hash = $this->inputs->quickHash($application, $round);

        // Content changed: earlier reviews of this application and round are stale
        ApplicationAiReview::where(['application_id' => $application->id, 'round_id' => $round->id])
            ->where('input_hash', '!=', $hash)->where('status', '!=', ApplicationAiReview::STATUS_STALE)
            ->update(['status' => ApplicationAiReview::STATUS_STALE]);

        $review = ApplicationAiReview::firstOrCreate(
            ['application_id' => $application->id, 'round_id' => $round->id, 'input_hash' => $hash],
            ['program_id' => $round->program_id, 'mode' => $round->review_method, 'status' => ApplicationAiReview::STATUS_QUEUED, 'prompt_version' => config('ai_review.prompt_version')]
        );

        $busy = in_array($review->status, [ApplicationAiReview::STATUS_QUEUED, ApplicationAiReview::STATUS_EXTRACTING, ApplicationAiReview::STATUS_ANALYZING], true);
        if (! $review->wasRecentlyCreated && ! $force) {
            return $review; // same input already reviewed (or queued): never run twice
        }
        if (! $review->wasRecentlyCreated && $force && ! $busy) {
            $review->update(['status' => ApplicationAiReview::STATUS_QUEUED, 'attempts' => 0, 'error' => null, 'completed_at' => null]);
        }

        // Stopped by the program owner: the review waits (queued) and is started when they continue
        if ($round->ai_review_paused_at) return $review;

        // The review row exists; the caller (the background worker) will run it itself
        if (! $run) return $review;

        // With no queue worker the review would run inside this request (an applicant waiting on their own submit): start it in the
        // background instead. With a worker it is queued as usual.
        if (BackgroundWork::needed()) {
            BackgroundWork::start($round);

            return $review;
        }

        RunApplicationAiReviewJob::dispatch($review->id);

        return $review;
    }
}
