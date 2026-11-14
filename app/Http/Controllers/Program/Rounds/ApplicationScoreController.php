<?php

namespace App\Http\Controllers\Program\Rounds;

use App\Http\Controllers\Controller;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationScore;

use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Models\ReviewerOrder;
use App\Service\Misc\ErrorLogService;
use App\Service\Program\RoundParticipation;
use App\Service\Program\RubricCalculator;
use App\Service\Program\ScoreAverager;
use App\Service\Notification\ProgramNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationScoreController extends Controller
{
    public function __construct()
    {
        // The base controller builds the shared services ($this->programNotification etc.).
        parent::__construct();
    }

    /**
     * Submit score for application
     * POST /api/v1/program/applications/{application}/scores
     */
    public function store(Request $request, ProgramApplication $application)
    {
        $userId = auth()->id();

        $round = $application->currentRound;

        if (!$round) {
            return response()->json(['error' => 'Application not in an active round'], 422);
        }

        // An AI-only round is scored by the AI alone: nobody enters or edits a score by hand
        if ($round->isAiOnly()) {
            return response()->json(['message' => 'This round is scored by the AI only, so scores cannot be entered or changed by hand.'], 422);
        }

        // Scoring closes once the round is finalized; results and reviewer pay are based on the scores as they stand.
        if ($round->status === 'finalized') {
            return response()->json(['message' => 'This round has been finalized. Scores can no longer be submitted or changed.'], 422);
        }

        // Nothing to score until the applicant has submitted this round (from round 2 on, advancing only places them in it)
        $participation = RoundParticipation::for($application, $round);
        if (! $participation['submitted']) {
            return response()->json([
                'message' => 'This applicant has not submitted ' . $round->round_name . ' yet, so it cannot be scored. You can score it once they submit.',
                'participation' => $participation,
            ], 422);
        }

        $isProgramOwner = $round->program->user_id === $userId;
        $isReviewer = $round->reviewers()->where('user_id', $userId)->exists() || $isProgramOwner;

        if (!$isReviewer) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!$isProgramOwner) {
            $reviewPeriodEnd = $round->review_period_end ?? $round->close_date;
            if (!$reviewPeriodEnd || now()->startOfDay()->gt($reviewPeriodEnd->copy()->startOfDay())) {
                return response()->json(['error' => 'The review period has ended; applications can no longer be scored.'], 422);
            }

            $isAssigned = ReviewerApplicationAssignment::where('round_id', $round->id)
                ->where('application_id', $application->id)
                ->where('reviewer_id', $userId)
                ->exists();
            if (!$isAssigned) {
                return response()->json(['error' => 'This application is not assigned to you for review.'], 403);
            }

            $order = ReviewerOrder::where('round_id', $round->id)
                ->where('reviewer_id', $userId)
                ->where('order_type', 'round_review')
                ->latest('id')->first();
            if (!$order || in_array($order->work_status, ['approved', 'rejected'], true)) {
                return response()->json(['error' => 'This reviewer order is no longer open for scoring.'], 422);
            }
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'criterion_scores'             => 'required|array',
                'criterion_scores.*.criterion' => 'required|string',
                'criterion_scores.*.score'     => 'required|numeric|min:0',
                'criterion_scores.*.comment'   => 'nullable|string',
                'overall_comment'              => 'nullable|string',
            ]);

            // Validate submitted criteria match round's defined scoring_criteria
            $definedCriteria   = collect($round->scoring_criteria)->pluck('name')->toArray();
            $submittedCriteria = collect($validated['criterion_scores'])->pluck('criterion')->toArray();
            $missing           = array_diff($definedCriteria, $submittedCriteria);

            if (!empty($missing)) {
                return response()->json([
                    'message' => 'Missing scores for criteria: ' . implode(', ', $missing)
                ], 422);
            }

            // Calculate total score based on rubric mode
            $totalScore = app(RubricCalculator::class)->calculate(
                $validated['criterion_scores'],
                $round->scoring_criteria,
                $round->rubric_mode ?? 'simple_total'
            );

            ApplicationScore::updateOrCreate(
                [
                    'application_id' => $application->id,
                    'round_id'       => $round->id,
                    'reviewer_id'    => $userId,
                ],
                [
                    'criterion_scores' => $validated['criterion_scores'],
                    'total_score'      => $totalScore,
                    'overall_comment'  => $validated['overall_comment'] ?? null,
                    'scored_at'        => now(),
                    'source'           => 'human',
                ]
            );

            // Human scores take precedence over any AI score when averaging
            $avgScore = app(ScoreAverager::class)->apply($application, $round);

            // Mark assignment completed
            ReviewerApplicationAssignment::where('round_id', $round->id)
                ->where('application_id', $application->id)
                ->where('reviewer_id', $userId)
                ->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                ]);

            // ─── Reviewer Order Status Updates ──────────────────────────────

            $this->updateReviewerOrderAfterScore($round);

            DB::commit();

            return response()->json([
                'message' => 'Score submitted successfully',
                'data'    => [
                    'rubric_mode'   => $round->rubric_mode,
                    'total_score'   => $totalScore,
                    'average_score' => $avgScore,
                ],
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong.'], 500);
        }
    }

    // ─── Score Calculation Methods ──────────────────────────────────────────────

    private function updateReviewerOrderAfterScore($round): void
    {
        // Update work_status to 'in_progress' on first score submission
        ReviewerOrder::where('round_id', $round->id)
            ->where('reviewer_id', Auth::id())
            ->where('work_status', 'assigned')
            ->update(['work_status' => 'in_progress']);

        // Meeting the agreed minimum paid workload automatically submits the order for owner review.
        $order = ReviewerOrder::where('round_id', $round->id)
            ->where('reviewer_id', Auth::id())
            ->where('order_type', 'round_review')
            ->latest('id')->first();

        $order?->refreshCompletedRoundReviewFee();

        $assignedToReviewer = $order?->applicationAssignments()->count() ?? 0;
        $completedByReviewer = $order?->applicationAssignments()->where('status', 'completed')->count() ?? 0;
        $minimumPaidApps = $order?->minimumPaidApplications() ?? 0;
        $paidThresholdReached = (float) ($order?->fee_amount ?? 0) > 0
            && $minimumPaidApps > 0
            && $completedByReviewer >= $minimumPaidApps;
        $unpaidWorkCompleted = (float) ($order?->fee_amount ?? 0) <= 0
            && $assignedToReviewer > 0
            && $completedByReviewer >= $assignedToReviewer;

        if ($order && ($paidThresholdReached || $unpaidWorkCompleted)
            && in_array($order->work_status, ['assigned', 'in_progress'], true)) {
            $order->update(['work_status' => 'delivered', 'delivered_at' => now()]);

            $this->programNotification->send('reviewer.scoring_complete', [$round->program->owner], [
                'program_title' => $round->program->program_title,
                'round_name' => $round->round_name,
                'reviewer_name' => Auth::user()->first_name . ' ' . Auth::user()->last_name,
                'order_id' => $order->id,
                'program_id' => $round->program_id,
                'round_id' => $round->id,
            ]);
        }
    }
}
