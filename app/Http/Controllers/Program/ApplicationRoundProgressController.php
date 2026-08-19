<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\ApplicationRoundHistory;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Models\Programs\Rounds\RoundRequiredDocument;
use App\Service\Program\RoundHelperService;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationRoundProgressController extends Controller
{
    /**
     * Get complete round progress for an application
     * GET /api/v1/program/applications/{application}/round-progress
     */
    public function index(ProgramApplication $application)
    {
        try {
            if ($application->user_id !== auth()->id()) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $roundService = new RoundHelperService();

            $program = $application->program;

            $rounds = ProgramRound::where('program_id', $program->id)
                ->orderBy('round_number')
                ->get();

            $history = ApplicationRoundHistory::where('application_id', $application->id)
                ->get()
                ->keyBy('round_id');

            $roundsData = $rounds->map(function ($round) use ($application, $history, $roundService) {
                return $roundService->buildRoundData(
                    $round,
                    $application,
                    $history->get($round->id)
                );
            })->values();

            return response()->json([
                'application' => [
                    'application_id' => $application->id,
                    'program_title' => $program->program_title,
                    'status' => $application->status,
                    'round_status' => $application->round_status,
                    'knockout_status' => $application->knockout_status,
                    'current_round_number' => $application->currentRound?->round_number,
                ],
                'rounds' => $roundsData
            ]);

        } catch (\Exception $e) {
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Get questions with answers for a specific round
     */
    private function getRoundQuestionsWithAnswers($roundId, $applicationId)
    {
        // Deduplicate by question_text + question_type — keep the latest per unique question
        $questions = RoundCustomQuestion::where('round_id', $roundId)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->unique(fn($q) => $q->question_text . '|' . $q->question_type)
            ->values();

        $responses = ApplicationRoundResponse::where('application_id', $applicationId)
            ->where('round_id', $roundId)
            ->get()
            ->keyBy('question_id');

        $regularQuestions = [];
        $knockoutQuestions = [];

        foreach ($questions as $question) {
            $response = $responses->get($question->id);

            $questionData = [
                'question_id' => $question->id,
                'question_text' => $question->question_text,
                'question_type' => $question->question_type,
                'is_required' => $question->is_required,
                'options' => $question->options,
                'display_order' => $question->display_order,
                'response' => $response?->response,
                'response_file' => $response?->file_path,
                'answered' => $response !== null,
                'answered_at' => $response?->created_at,
            ];

            if ($question->question_type === 'knockout') {
                $questionData['knockout_fail_value'] = $question->knockout_fail_value;
                $knockoutQuestions[] = $questionData;
            } else {
                $regularQuestions[] = $questionData;
            }
        }

        return [
            'regular' => $regularQuestions,
            'knockout' => $knockoutQuestions,
            'total' => count($regularQuestions) + count($knockoutQuestions),
            'answered' => $responses->count(),
        ];
    }

    public function publish(ProgramRound $round)
    {
        $userId = auth()->id();

        if ($round->program->user_id !== $userId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($round->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft rounds can be published. Current status: ' . $round->status
            ], 422);
        }

        // Checklist validation
        $errors = [];

        if (!$round->open_date || !$round->close_date) {
            $errors['dates'] = 'Round must have open and close dates.';
        }

        if ($round->open_date && $round->open_date < now()) {
            $errors['open_date'] = 'Open date must be in the future.';
        }

        if ($round->open_date && $round->close_date && $round->close_date <= $round->open_date) {
            $errors['close_date'] = 'Close date must be after open date.';
        }

        if (empty($round->scoring_criteria)) {
            $errors['scoring_criteria'] = 'Round must have scoring criteria defined.';
        }

        if ($round->advancement_mode === 'score_threshold' && !$round->score_threshold) {
            $errors['score_threshold'] = 'Score threshold must be set for score threshold advancement mode.';
        }

        if ($round->advancement_mode === 'fixed_quota' && !$round->max_advancing) {
            $errors['max_advancing'] = 'Max advancing must be set for fixed quota advancement mode.';
        }

        if ($round->assignment_type !== 'owner_only' && !$round->reviewers()->exists()) {
            $errors['reviewers'] = 'At least one reviewer must be assigned before publishing.';
        }

        if ($round->round_number > 1) {
            $previousRound = ProgramRound::where('program_id', $round->program_id)
                ->where('round_number', $round->round_number - 1)
                ->first();

            if (!$previousRound || $previousRound->status !== 'finalized') {
                $errors['previous_round'] = 'Previous round must be finalized before publishing this round.';
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'message' => 'Round is not ready to be published.',
                'errors'  => $errors,
            ], 422);
        }

        try {
            $round->status = 'published';
            $round->save();

            return response()->json(['message' => 'Round published successfully.'], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    public function activeApplications(ProgramRound $round)
    {
        $applications = ProgramApplication::where('current_round_id', $round->id)
            ->latest()->get()->makeHidden('score_breakdown');

        return response()->json([
            'round_id' => $round->id,
            'round_name' => $round->round_name,
            'type' => 'active',
            'total' => $applications->count(),
            'applications' => $applications,
        ], 200);
    }

    //✅ ADVANCED
    public function advancedApplications(ProgramRound $round)
    {
        $applications = ApplicationRoundHistory::where('round_id', $round->id)
            ->where('outcome', 'advanced')
            ->latest()->get()
            ->map(function ($history) {

                $app = $history->application;

                return [
                    ...$app->toArray(),

                    'round_outcome' => $history->outcome,
                    'round_score' => $history->average_score,
                    'rank_in_round' => $history->rank_in_round,
                ];
            });

        return response()->json([
            'round_id' => $round->id,
            'round_name' => $round->round_name,
            'type' => 'advanced',
            'total' => $applications->count(),
            'applications' => $applications,
        ], 200);
    }

    //✅ NOT SELECTED
    public function notSelectedApplications(ProgramRound $round)
    {
        $applications = ApplicationRoundHistory::where('round_id', $round->id)
            ->where('outcome', 'not_selected')
            ->latest()->get()
            ->map(function ($history) {

                $app = $history->application;

                return [
                    ...$app->toArray(),

                    'round_outcome' => $history->outcome,
                    'round_score' => $history->average_score,
                    'review_notes' => $history->outcome_notes,
                    'reviewed_at' => $history->updated_at,
                ];
            });

        return response()->json([
            'round_id' => $round->id,
            'round_name' => $round->round_name,
            'type' => 'not_selected',
            'total' => $applications->count(),
            'applications' => $applications,
        ], 200);
    }

    // H E L P E R S

    /**
     * Get documents for a specific round
     */

    // extra - auto score apps
    public function autoScoreApplications(Request $request, ProgramRound $round)
    {

        if ($round->program->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        try {
            $validated = $request->validate([
                'application_start_id' => 'required|integer',
                'application_end_id'   => 'required|integer|gte:application_start_id',
            ]);

            $startId = $validated['application_start_id'];
            $endId   = $validated['application_end_id'];

            $scoringCriteria = $round->scoring_criteria;

            if (empty($scoringCriteria)) {
                return response()->json([
                    'message' => 'Round has no scoring criteria defined.'
                ], 422);
            }

            $applications = ProgramApplication::where('current_round_id', $round->id)
                //->whereBetween('id', [$startId, $endId])
                ->whereIn('round_status', ['draft','submitted', 'under_review'])
                ->get();

            if ($applications->isEmpty()) {
                return response()->json([
                    'message' => 'No eligible applications found in that ID range for this round.'
                ], 422);
            }

            $scorerId = $round->program->user_id;

            // Shuffle and split - first 50 get high scores, rest get low
            $shuffled  = $applications->shuffle();
            $highScore = $shuffled->take(25);       // these will avg > 50
            $lowScore  = $shuffled->skip(25);       // these will avg < 50

            $highScoreIds = $highScore->pluck('id')->toArray();

            DB::beginTransaction();

            $scored = [];

            foreach ($applications as $app) {
                $isHigh = in_array($app->id, $highScoreIds);

                $criterionScores = [];
                $totalScore      = 0;

                foreach ($scoringCriteria as $criterion) {
                    $maxScore = $criterion['score_range'] ?? 100;

                    // High scorers: 60-100, Low scorers: 10-45
                    $rawScore = $isHigh
                        ? rand(60, $maxScore)
                        : rand(10, 45);

                    $criterionScores[] = [
                        'criterion' => $criterion['name'],
                        'score'     => $rawScore,
                        'comment'   => 'Auto scored',
                    ];

                    $totalScore += $rawScore;
                }

                // Average across criteria
                $totalScore = round($totalScore / count($scoringCriteria), 2);

                ApplicationScore::updateOrCreate(
                    [
                        'application_id' => $app->id,
                        'round_id'       => $round->id,
                        'reviewer_id'    => $scorerId,
                    ],
                    [
                        'criterion_scores' => $criterionScores,
                        'total_score'      => $totalScore,
                        'overall_comment'  => 'Auto scored for testing',
                        'scored_at'        => now(),
                    ]
                );

                $avgScore = ApplicationScore::where('application_id', $app->id)
                    ->where('round_id', $round->id)
                    ->avg('total_score');

                $avgScore = round($avgScore, 2);

                $isEligible = match($round->advancement_mode) {
                    'score_threshold' => $avgScore >= $round->score_threshold,
                    'fixed_quota'     => true,
                    'manual'          => true,
                    default           => false,
                };

                $app->update([
                    'average_score'          => $avgScore,
                    'round_status'           => 'scored',
                    'knockout_status'        => 'passed',
                    'is_eligible_to_advance' => $isEligible,
                ]);

                $scored[] = [
                    'application_id' => $app->id,
                    'average_score'  => $avgScore,
                    'group'          => $isHigh ? 'high' : 'low',
                    'is_eligible'    => $isEligible,
                ];
            }

            DB::commit();

            $highCount = collect($scored)->where('group', 'high')->count();
            $lowCount  = collect($scored)->where('group', 'low')->count();

            return response()->json([
                'message'    => count($scored) . ' applications auto scored successfully.',
                'round'      => $round->round_name,
                'high_score_count' => $highCount,   // avg > 50
                'low_score_count'  => $lowCount,    // avg < 50
                'scored'     => $scored,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }
}
