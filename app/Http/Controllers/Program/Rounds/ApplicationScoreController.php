<?php

namespace App\Http\Controllers\Program\Rounds;

use App\Http\Controllers\Controller;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\ReviewerOrder;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\ProgramNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationScoreController extends Controller
{
    public function __construct(
        private ProgramNotificationService $notification,
    ) {}

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

        $isReviewer = $round->reviewers()->where('user_id', $userId)->exists()
            || $round->program->user_id === $userId;

        if (!$isReviewer) {
            return response()->json(['error' => 'Unauthorized'], 403);
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
            $totalScore = $this->calculateScore(
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
                ]
            );

            $avgScore = ApplicationScore::where('application_id', $application->id)
                ->where('round_id', $round->id)
                ->avg('total_score');

            $avgScore = round($avgScore, 2);

            $application->average_score  = $avgScore;
            $application->round_status   = 'scored';

            $application->is_eligible_to_advance = match($round->advancement_mode) {
                'score_threshold' => $avgScore >= $round->score_threshold,
                'fixed_quota'     => true,
                'manual'          => true,
                default           => false,
            };

            $application->save();

            // ─── Reviewer Order Status Updates ──────────────────────────────

            // Update work_status to 'in_progress' on first score submission
            ReviewerOrder::where('round_id', $round->id)
                ->where('reviewer_id', Auth::id())
                ->where('work_status', 'assigned')
                ->update(['work_status' => 'in_progress']);

            // Check if reviewer has scored all their assigned apps for this round
            // Get all applications in this round that are being reviewed
            $allAppsInRound = ProgramApplication::where('current_round_id', $round->id)
                ->where('round_status', '!=', 'not_selected')
                ->count();

            $scoredByReviewer = ApplicationScore::where('round_id', $round->id)
                ->where('reviewer_id', Auth::id())
                ->count();

            // If reviewer has scored all apps assigned to them, mark as delivered
            if ($scoredByReviewer >= $allAppsInRound) {
                $order = ReviewerOrder::where('round_id', $round->id)
                    ->where('reviewer_id', Auth::id())
                    ->first();

                if ($order && $order->work_status === 'in_progress') {
                    $order->update([
                        'work_status'  => 'delivered',
                        'delivered_at' => now(),
                    ]);

                    // Notify program owner
                    $this->notification->send('reviewer.scoring_complete', [
                        $round->program->owner
                    ], [
                        'program_title' => $round->program->program_title,
                        'round_name'    => $round->round_name,
                        'reviewer_name' => Auth::user()->first_name . ' ' . Auth::user()->last_name,
                        'order_id'      => $order->id,
                    ]);
                }
            }

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

    private function calculateScore(array $criterionScores, array $scoringCriteria, string $rubricMode): float
    {
        return match($rubricMode) {
            'weighted'     => $this->calculateWeighted($criterionScores, $scoringCriteria),
            'pass_fail'    => $this->calculatePassFail($criterionScores),
            'simple_total' => $this->calculateSimpleTotal($criterionScores),
            default        => $this->calculateSimpleTotal($criterionScores),
        };
    }

    private function calculateSimpleTotal(array $criterionScores): float
    {
        // Just sum all raw scores
        return round(array_sum(array_column($criterionScores, 'score')), 2);
    }

    private function calculateWeighted(array $criterionScores, array $scoringCriteria): float
    {
        // Weight defined in scoring_criteria e.g. [{name, weight: 30, score_range: 100}]
        $criteriaMap = collect($scoringCriteria)->keyBy('name');
        $totalScore  = 0;

        foreach ($criterionScores as $criterion) {
            $config     = $criteriaMap->get($criterion['criterion']);
            $weight     = $config['weight'] ?? 0;      // e.g. 30 means 30%
            $scoreRange = $config['score_range'] ?? 100;

            // Normalize score to 0-100 then apply weight
            $normalized  = ($criterion['score'] / $scoreRange) * 100;
            $totalScore += $normalized * ($weight / 100);
        }

        return round($totalScore, 2);
    }

    private function calculatePassFail(array $criterionScores): float
    {
        // Each criterion either passes (score > 0) or fails (score = 0)
        // Total = percentage of criteria passed
        $total  = count($criterionScores);
        $passed = count(array_filter($criterionScores, fn($c) => $c['score'] > 0));

        return round(($passed / $total) * 100, 2);
    }

}
