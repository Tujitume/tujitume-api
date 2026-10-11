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
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
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
                    'grant_title' => $program->program_title,
                    'total_rounds' => (int) ($program->total_rounds ?: $rounds->count()),
                    'submitted_at' => $application->created_at,
                    'status' => $application->status,
                    'round_status' => $application->round_status,
                    'knockout_status' => $application->knockout_status,
                    'average_score' => $application->average_score,
                    'is_eligible_to_advance' => $application->is_eligible_to_advance,
                    'current_round_number' => $application->currentRound?->round_number,
                ],
                // Where the applicant is and what to do next, worked out here so every screen says the same thing
                'journey' => $this->journey($application, $program, $rounds, $roundsData),
                'rounds' => $roundsData
            ]);

        } catch (\Exception $e) {
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * The applicant's position in plain words: which round they are in, whether it is open, what is left to do and
     * what happens next. Nothing here is stored; it is read from the round and the application as they are now.
     */
    private function journey(ProgramApplication $application, $program, $rounds, $roundsData): ?array
    {
        $round = $rounds->firstWhere('id', $application->current_round_id);
        if (! $round) {
            return null;
        }

        $data = collect($roundsData)->firstWhere('round_id', $round->id) ?? [];
        $total = (int) ($program->total_rounds ?: $rounds->count());
        $date = fn ($d) => $d?->format('j M Y');
        $roundLabel = 'Round ' . $round->round_number;
        $roundRs = (string) $application->round_status;

        // Round 1 is submitted by applying; from round 2 on, "draft" means nothing has been handed in yet
        $submitted = (int) $round->round_number === 1
            ? true
            : in_array($roundRs, ['submitted', 'assigned', 'under_review', 'scored', 'advanced', 'not_selected'], true);

        $notOpen = $round->status === 'draft' || ($round->open_date && $round->open_date->copy()->startOfDay()->isFuture());
        $closed = in_array($round->status, ['closed', 'in_review', 'finalized'], true)
            || ($round->close_date && $round->close_date->copy()->endOfDay()->isPast());

        $knockout = $data['questions']['knockout'] ?? [];
        $regular = $data['questions']['regular'] ?? [];
        $completion = $data['completion'] ?? [];

        // The steps of this round, each with whether it is done
        $steps = [];
        if (count($knockout) > 0) {
            $answered = collect($knockout)->where('answered', true)->count();
            $steps[] = ['key' => 'eligibility', 'label' => 'Answer the eligibility questions', 'done' => $answered === count($knockout), 'detail' => "{$answered} of " . count($knockout) . ' answered'];
        }
        if (($completion['questions']['required'] ?? 0) > 0) {
            $steps[] = ['key' => 'questions', 'label' => 'Answer the round questions', 'done' => (bool) ($completion['questions']['all_required_answered'] ?? false),
                'detail' => ($completion['questions']['answered_required'] ?? 0) . ' of ' . ($completion['questions']['required'] ?? 0) . ' required answered'];
        }
        if (($completion['documents']['required'] ?? 0) > 0) {
            $steps[] = ['key' => 'documents', 'label' => 'Upload the required documents', 'done' => (bool) ($completion['documents']['all_uploaded'] ?? false),
                'detail' => ($completion['documents']['uploaded'] ?? 0) . ' of ' . ($completion['documents']['required'] ?? 0) . ' uploaded'];
        }
        $steps[] = ['key' => 'submit', 'label' => 'Submit the round', 'done' => $submitted, 'detail' => $submitted ? 'Submitted' : 'When everything above is done'];

        $firstOpenStep = collect($steps)->firstWhere('done', false);
        $allReady = collect($steps)->where('key', '!=', 'submit')->every(fn ($step) => $step['done']);

        // One state, one headline, one sentence
        if ($application->status === 'awarded') {
            $state = 'awarded';
            $headline = 'Congratulations, you were awarded';
            $message = 'Open your deal room to set up your funding plan.';
        } elseif ($application->status === 'rejected' || $roundRs === 'not_selected') {
            $state = 'not_selected';
            $headline = 'You were not selected to continue';
            $message = 'Thank you for taking part. You can look at other programs and apply again.';
        } elseif ($application->knockout_status === 'failed') {
            $state = 'knockout_failed';
            $headline = 'An eligibility answer does not qualify';
            $message = 'One of your eligibility answers does not meet the requirement for this round.';
        } elseif ($submitted) {
            $state = 'submitted';
            $headline = "{$roundLabel} submitted";
            $message = 'Your answers are with the review team. '
                . ($round->announcement_date ? 'Results are expected on ' . $date($round->announcement_date) . '.' : 'You will be told the result by email and in the app.');
        } elseif ($notOpen) {
            $state = 'not_open';
            $headline = "{$roundLabel} has not opened yet";
            $message = ($round->open_date ? 'It opens on ' . $date($round->open_date) . '. ' : 'The organization has not set the date yet. ')
                . 'You were accepted into this round. You can read what it asks for now, and submit once it opens.';
        } elseif ($closed) {
            $state = 'missed';
            $headline = "{$roundLabel} is closed";
            $message = 'The deadline has passed, so nothing more can be submitted for this round.';
        } else {
            $state = 'in_progress';
            $headline = "Your turn: complete {$roundLabel}";
            $message = ($round->close_date ? 'Submit before ' . $date($round->close_date) . '. ' : '')
                . ($allReady ? 'Everything is filled in, so you can submit.' : 'Work through the steps below.');
        }

        return [
            'state' => $state,
            'round_number' => (int) $round->round_number,
            'total_rounds' => $total,
            'round_name' => $round->round_name,
            'headline' => $headline,
            'message' => $message,
            'opens' => $date($round->open_date),
            'closes' => $date($round->close_date),
            'results' => $date($round->announcement_date),
            'can_submit' => ! $submitted && ! $notOpen && ! $closed && $allReady,
            'next_step' => in_array($state, ['in_progress', 'not_open'], true) ? ($firstOpenStep && $firstOpenStep['key'] !== 'submit' ? $firstOpenStep['label'] : ($state === 'in_progress' ? 'Submit the round' : null)) : null,
            'steps' => in_array($state, ['in_progress', 'not_open', 'missed'], true) ? $steps : [],
        ];
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

        if (!\App\Service\Program\ProgramAccess::owns($round->program)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($round->status !== 'draft') {
            return response()->json([
                'message' => $round->status === 'published'
                    ? 'This round is already published.'
                    : 'This round can no longer be published because it is ' . str_replace('_', ' ', $round->status) . '.'
            ], 422);
        }

        // The owner chooses when it opens: 'now' makes today the open date, 'on_open_date' keeps the date they set
        $mode = request()->input('mode');
        if ($mode === 'now') {
            $round->open_date = now()->startOfDay();
        }

        // Checklist validation
        $errors = [];

        // Every setup step must have been completed and confirmed by the person, not just have default data.
        foreach (app(\App\Http\Controllers\Program\Rounds\ProgramRoundController::class)->unfinishedSteps($round) as $step => $stepLabel) {
            $errors['step_' . $step] = $stepLabel . ' is not finished yet. Complete it before publishing.';
        }

        if (!$round->open_date || !$round->close_date) {
            $errors['dates'] = 'Round must have open and close dates.';
        }

        // Today is fine: only a day that has already passed is refused
        if ($round->open_date && $round->open_date->copy()->startOfDay() < now()->startOfDay()) {
            $errors['open_date'] = 'Open date cannot be in the past. Choose today or a later date.';
        }

        if ($round->open_date && $round->close_date && $round->close_date <= $round->open_date) {
            $errors['close_date'] = 'Close date must be after open date.';
        }

        if ($round->advancement_mode == 'score_threshold' && empty($round->scoring_criteria)) {
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

        // if ($round->assignment_type !== 'owner_only') {
        //     $totalFees = DB::table('round_reviewers')
        //         ->where('round_id', $round->id)
        //         ->sum('reviewer_fee');

        //     if ($totalFees > 0) {
        //         $wallet = $round->program->wallet;
        //         if (!$wallet || $wallet->balance < $totalFees) {
        //             $errors['wallet'] = "Insufficient wallet balance to cover reviewer fees. Required: {$totalFees}, Available: " . ($wallet?->balance ?? 0);
        //         }
        //     }
        // }


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

            $opensNow = ! $round->open_date || $round->open_date->copy()->startOfDay()->lessThanOrEqualTo(now()->startOfDay());

            // Publishing the first round is what opens the program to applicants: it must be listed for them, with its dates
            // (a duplicated program starts hidden and without dates until this happens)
            if ((int) $round->round_number === 1) {
                $program = $round->program;
                $program->visible = 1;
                $program->status = 'published';
                if (! $program->start_date && $round->open_date) {
                    $program->start_date = $round->open_date->toDateString();
                }
                if (! $program->application_deadline && $round->close_date) {
                    $program->application_deadline = $round->close_date->toDateString();
                }
                $program->save();
            }

            return response()->json([
                'message' => $opensNow
                    ? 'Round published. It is open now.'
                    : 'Round published. It opens on ' . $round->open_date->format('j M Y') . '.',
                'opens_now' => $opensNow,
                'open_date' => $round->open_date?->toDateString(),
            ], 200);

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

        if (!\App\Service\Program\ProgramAccess::owns($round->program)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        try {
            $validated = $request->validate([
                'application_start_id' => 'nullable|integer',
                'application_end_id'   => 'nullable|integer|gte:application_start_id',
            ]);

            //$startId = $validated['application_start_id'];
            //$endId   = $validated['application_end_id'];

            $scoringCriteria = $round->scoring_criteria;

            if (empty($scoringCriteria)) {
                return response()->json([
                    'message' => 'Round has no scoring criteria defined.'
                ], 422);
            }

            $applications = ProgramApplication::where('current_round_id', $round->id)
                //->whereBetween('id', [$startId, $endId])
                ->whereIn('round_status', ['draft','submitted', 'assigned', 'under_review'])
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
