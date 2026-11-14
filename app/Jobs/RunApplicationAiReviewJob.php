<?php

namespace App\Jobs;

use App\Models\Programs\Ai\ApplicationAiReview;
use App\Service\AiReview\AiReviewRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One AI review. Waits (release) when a provider's quota is used up; never fails an application because of it. */
class RunApplicationAiReviewJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;          // retried by release(); bounded by retryUntil
    public int $timeout = 240;
    public int $uniqueFor = 600;

    public function __construct(public int $reviewId)
    {
        $this->onQueue(config('ai_review.queue'));
    }

    public function uniqueId(): string
    {
        return 'ai-review-'.$this->reviewId;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(2);
    }

    public function handle(AiReviewRunner $runner): void
    {
        $review = ApplicationAiReview::find($this->reviewId);
        if (! $review || $review->isFinished() && $review->status !== ApplicationAiReview::STATUS_QUEUED) return;

        // Stopped by the program owner after this job was queued: leave the review waiting; Continue starts it again
        if ($review->round?->ai_review_paused_at) return;

        $result = $runner->run($review);
        if ($result['release'] !== null) {
            $this->release($result['release']);
        }
    }
}
