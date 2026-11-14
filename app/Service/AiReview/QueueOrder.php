<?php

namespace App\Service\AiReview;

use App\Models\ApplicationRoundHistory;
use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use Illuminate\Support\Carbon;

/**
 * Who is next, first come first served: whichever application arrived first, whether it already has a queued review or has none yet.
 * Arrival is when its review was queued, or, for one with no review yet, when it was submitted to this round. One rule, used by the
 * background worker and by the "Next in line" card, so what you see is what runs.
 */
class QueueOrder
{
    /** @return array{review:?ApplicationAiReview, application:?ProgramApplication, at:Carbon}|null */
    public static function next(ProgramRound $round, array $skipReviewIds = []): ?array
    {
        $reviewable = fn () => ProgramApplication::where('program_applications.current_round_id', $round->id)
            ->whereIn('program_applications.round_status', ProgramApplication::AI_REVIEWABLE_STATUSES)->notAutomated();

        $review = ApplicationAiReview::where('round_id', $round->id)->where('status', ApplicationAiReview::STATUS_QUEUED)->whereNotIn('id', $skipReviewIds)
            ->whereIn('application_id', $reviewable()->select('program_applications.id'))->orderBy('updated_at')->orderBy('id')->first();

        $history = (new ApplicationRoundHistory())->getTable();
        $arrival = "COALESCE((select h.submitted_at from {$history} h where h.application_id = program_applications.id and h.round_id = ? limit 1), program_applications.created_at)";
        $application = $reviewable()
            ->whereNotIn('program_applications.id', ApplicationAiReview::where('round_id', $round->id)->where('status', '!=', ApplicationAiReview::STATUS_STALE)->select('application_id'))
            ->select('program_applications.*')->selectRaw("{$arrival} as arrived_at", [$round->id])
            ->orderByRaw($arrival, [$round->id])->orderBy('program_applications.id')->first();

        if (! $review && ! $application) return null;
        $reviewAt = $review ? Carbon::parse($review->updated_at) : null;
        $applicationAt = $application ? Carbon::parse($application->arrived_at) : null;

        // The earlier arrival goes first; the same moment goes to the review that was already queued
        if ($review && (! $application || $reviewAt->lte($applicationAt))) {
            return ['review' => $review, 'application' => null, 'at' => $reviewAt];
        }

        return ['review' => null, 'application' => $application, 'at' => $applicationAt];
    }
}
