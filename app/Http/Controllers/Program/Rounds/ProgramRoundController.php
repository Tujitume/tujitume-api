<?php

namespace App\Http\Controllers\Program\Rounds;

use App\Http\Controllers\Controller;
use App\Models\ApplicationRoundHistory;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Models\Programs\Rounds\RoundRequiredDocument;
use App\Models\ProgramEmailTemplate;
use App\Models\ReviewerOrder;
use App\Service\Program\KnockoutEvaluator;
use App\Service\Program\RoundFinalizationService;
use App\Service\Program\ProgramAccess;
use App\Support\ApiContract;
use App\Service\Program\RoundHelperService;
use App\Service\Program\RoundHistoryService;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\ProgramNotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgramRoundController extends Controller
{
    /**
     * Create a round
     * POST /api/v1/program/programs/{program}/rounds
     */

    protected $roundHistory;
    protected $programNotification;

    public function __construct()
    {
        $this->roundHistory = new RoundHistoryService();
        $this->programNotification = new ProgramNotificationService();
        parent::__construct();
    }
    public function index(Program $program){
        $rounds = ProgramRound::with('applications')
            ->withCount('applications')
            ->where('program_id', $program->id)
            ->get()->map(function($round) use ($program){
                return[
                    ...$round->toArray(),
                    'status' => ApiContract::statusMeta('program_round', $round->status),
                    // A multi-round program is only finished at its last planned round, not at the last one created so far
                    'program_total_rounds' => (int) ($program->total_rounds ?? 1),
                ];
            });

        return ApiContract::listResponse('Rounds fetched successfully.', $rounds, null, ['rounds']);
    }

    //added by owen
    public function showRound(ProgramRound $round)
    {
        $userId = auth()->id();

        $roleUser = auth()->user()?->organization_id === $round->program->user?->organization_id;


        if (!\App\Service\Program\ProgramAccess::owns($round->program) && !$roleUser) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        return response()->json([
            'data' => [
                ...$round->toArray(),
                'status' => ApiContract::statusMeta('program_round', $round->status),
                'reviewers' => $round->reviewers,
                'configuration_progress' => $this->configurationProgress($round),
            ]
        ], 200);
    }

    /**
     * Is this the program's last planned round, the one whose advancing applicants are awarded?
     * Rounds are created one at a time, so "no later round exists yet" says nothing: a 3-round program with
     * only round 1 created is not finished after round 1.
     */
    private function isFinalRound(ProgramRound $round): bool
    {
        $program = $round->program;

        if (($program->program_type ?? 'single_round') !== 'multi_round') {
            return true;
        }

        return (int) $round->round_number >= max(1, (int) ($program->total_rounds ?? 1));
    }

    private function nextRoundExists(ProgramRound $round): bool
    {
        return ProgramRound::where('program_id', $round->program_id)
            ->where('round_number', $round->round_number + 1)
            ->exists();
    }

    // The wizard steps the person has confirmed, stored on the round (program_rounds.confirmed_steps)
    private function confirmedSteps(ProgramRound $round): array
    {
        return (array) ($round->confirmed_steps ?? []);
    }

    /** The wizard steps a person confirms themselves; publishing is decided by the round's status. */
    private const CONFIRMABLE_STEPS = ['setup', 'questions', 'reviewers', 'notifications'];

    /**
     * Where the person is in the round wizard. A step is done when its data is valid AND the person has
     * confirmed it (saved or moved on). Data alone is not enough: the first round is created with the
     * program, so its dates and questions exist before anyone has opened the wizard.
     * A round that is already live counts every step as done.
     */
    /** Wizard steps (before publish) that are not done yet, as step => label, for the publish check. */
    public function unfinishedSteps(ProgramRound $round): array
    {
        $labels = [
            'setup' => 'Round setup (dates and scoring)',
            'questions' => 'Application questions',
            'reviewers' => 'Reviewers',
            'notifications' => 'Notification emails',
        ];

        $steps = $this->configurationProgress($round)['steps'];

        return collect($labels)->filter(fn ($label, $step) => empty($steps[$step]))->all();
    }

    private function configurationProgress(ProgramRound $round, bool $requireConfirmation = true): array
    {
        $isLive = $round->status !== 'draft';
        $confirmed = $this->confirmedSteps($round);
        $confirms = fn (string $step) => ! $requireConfirmation || $isLive || in_array($step, $confirmed, true);

        $steps = [
            'setup' => $this->setupStepComplete($round) && $confirms('setup'),
            'questions' => RoundCustomQuestion::where('round_id', $round->id)
                ->where('question_type', '!=', 'knockout')
                ->exists() && $confirms('questions'),
            'reviewers' => ($round->assignment_type === 'owner_only'
                || $round->reviewers()->count() >= max(1, (int) ($round->min_reviewers_required ?? 1)))
                && $confirms('reviewers'),
            'notifications' => ProgramEmailTemplate::where('program_id', $round->program_id)
                ->whereIn('event', ['round.advanced', 'round.not_selected'])
                ->whereNotNull('body_html')
                ->where('body_html', '!=', '')
                ->pluck('event')
                ->unique()
                ->count() === 2 && $confirms('notifications'),
            'publish' => $isLive,
        ];

        $completed = collect($steps)
            ->filter()
            ->keys()
            ->values()
            ->all();

        $stepOrder = array_keys($steps);
        $activeStep = collect($stepOrder)->search(fn ($step) => !$steps[$step]);

        return [
            'steps' => $steps,
            'completed_steps' => $completed,
            'active_step' => $activeStep === false ? count($stepOrder) - 1 : $activeStep,
            'active_step_id' => $activeStep === false ? end($stepOrder) : $stepOrder[$activeStep],
        ];
    }

    /**
     * The person confirms a wizard step (saved it, or moved on from it).
     * POST /programs/rounds/{round}/configuration-steps  { step }
     * Refused while the step's data is still incomplete, so a step can never show done when it is not.
     */
    public function confirmStep(Request $request, ProgramRound $round)
    {
        if (!\App\Service\Program\ProgramAccess::owns($round->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $step = $request->validate([
            'step' => 'required|string|in:' . implode(',', self::CONFIRMABLE_STEPS),
        ])['step'];

        $ready = $this->configurationProgress($round, false)['steps'][$step] ?? false;
        if (!$ready) {
            return response()->json([
                'message' => 'This step is not complete yet.',
                'configuration_progress' => $this->configurationProgress($round),
            ], 422);
        }

        $confirmed = collect($this->confirmedSteps($round))->push($step)->unique()->values()->all();
        $round->update(['confirmed_steps' => $confirmed]);

        return response()->json([
            'message' => 'Step confirmed.',
            'configuration_progress' => $this->configurationProgress($round->fresh()),
        ], 200);
    }

    private function setupStepComplete(ProgramRound $round): bool
    {
        if (!$round->round_name || !$round->open_date || !$round->close_date) {
            return false;
        }

        $criteria = $round->scoring_criteria ?? [];
        if (!empty($criteria)) {
            foreach ($criteria as $criterion) {
                if (empty($criterion['name'])) {
                    return false;
                }
            }

            if (($round->rubric_mode ?? 'weighted') === 'weighted') {
                $totalWeight = collect($criteria)->sum(fn ($criterion) => (int) ($criterion['weight'] ?? 0));
                if ($totalWeight !== 100) {
                    return false;
                }
            }
        }

        foreach ($round->required_documents ?? [] as $document) {
            if (empty($document['label'])) {
                return false;
            }
        }

        return true;
    }

    # get applications for a round
    public function applications($round_id)
    {
        $round = ProgramRound::findOrFail($round_id);
        (new ProgramAccess())->authorizeRound(auth()->user(), $round);

        if ($round->status === 'finalized') {
            // Round is done - get all apps from history
            $appIds = ApplicationRoundHistory::where('round_id', $round_id)
                ->pluck('application_id');

            $applications = ProgramApplication::with('reviewer')->whereIn('id', $appIds)->get();

        } else {
            // Round still active - get current apps
            $applications = ProgramApplication::with('reviewer')->where('current_round_id', $round_id)->get();
        }

        // Stats always from history (accurate regardless of round state)
        $history = ApplicationRoundHistory::where('round_id', $round_id)->get();

        $stats = [
            'total'              => $applications->count(),
            'advanced_count'     => $history->where('outcome', 'advanced')->count(),
            'not_selected_count' => $history->where('outcome', 'not_selected')->count(),
            'awarded_count'      => $history->where('outcome', 'awarded')->count(),
            'in_progress_count'  => $history->where('outcome', 'in_progress')->count(),
            //'reviewed_by'         => $round->reviewers,
        ];

        $scoresByApplication = \App\Models\Programs\Rounds\ApplicationScore::where('round_id', $round_id)
            ->whereIn('application_id', $applications->pluck('id'))
            ->get()
            ->groupBy('application_id');

        $mapped = $applications->map(function ($app) use ($round_id, $round, $scoresByApplication) {
            // Get this app's outcome in this specific round from history
            $roundHistory = ApplicationRoundHistory::where('application_id', $app->id)
                ->where('round_id', $round_id)
                ->first();

            $roundScores = $scoresByApplication->get($app->id, collect());
            $criteriaCount = is_array($round->scoring_criteria) ? count($round->scoring_criteria) : 0;
            $average = $roundScores->isNotEmpty() ? round((float) $roundScores->avg('total_score'), 1) : null;

            return [
                ...$app->toArray(),
                // What the reviewers said, ready to show in the table (sortable, and good for deciding a manual round)
                'review_summary' => [
                    'reviewers_scored' => $roundScores->count(),
                    'average_score' => $average,
                    'rubric_mode' => $round->rubric_mode,
                    'criteria_count' => $criteriaCount,
                    'passed' => ($round->rubric_mode === 'pass_fail' && $average !== null && $criteriaCount > 0)
                        ? (int) round(($average / 100) * $criteriaCount)
                        : null,
                ],
                'round_outcome' => $roundHistory?->outcome,      // ← what happened in THIS round
                'round_score'   => $roundHistory?->average_score, // ← score in THIS round
                'status' => [
                    'value' => $app->status,
                    'color' => config('status.program_application.' . $app->status, 'gray'),
                ],
                'round_status' => [
                    'value' => $app->round_status,
                    'color' => config('status.program_application_round.' . $app->round_status, 'gray'),
                ],
                'knockout_status' => [
                    'value' => $app->knockout_status,
                    'color' => config('status.knockout.' . $app->knockout_status, 'gray'),
                ],
                'funding_setup_status' => [
                    'value' => $app->funding_setup_status,
                    'color' => config('status.funding_setup.' . $app->funding_setup_status, 'gray'),
                ],
            ];
        });

        return response()->json([
            'applications' => $mapped,
            'stats'        => $stats,
        ], 200);
    }

    public function application_show($application_id)
    {
        $application = ProgramApplication::with([
            'currentRound.questions',
            'currentRound.knockoutQuestions',
            'roundAnswers',
            'scores',
            'scores.reviewer',
            'roundDocuments',
        ])->findOrFail($application_id);  // ← also fixed: use findOrFail not where()->get()

        (new ProgramAccess())->authorizeApplication(auth()->user(), $application);

        // Is this application assigned to the signed-in person to review? (a team member or an external reviewer)
        // The page uses this to show them the scoring, instead of the program owner's view.
        $myReviewAssignment = \App\Models\Programs\Rounds\ReviewerApplicationAssignment::where('application_id', $application->id)
            ->where('reviewer_id', auth()->id())
            ->latest('id')
            ->first();

        return response()->json([
            'application' => [
                ...$application->toArray(),
                'my_review_assignment' => $myReviewAssignment,
                'status' => [
                    'value' => $application->status,
                    'color' => config('status.program_application.' . $application->status, 'gray'),
                ],
                'round_status' => [
                    'value' => $application->round_status,
                    'color' => config('status.program_application_round.' . $application->round_status, 'gray'),
                ],
                'knockout_status' => [
                    'value' => $application->knockout_status,
                    'color' => config('status.knockout.' . $application->knockout_status, 'gray'),
                ],
                'funding_setup_status' => [
                    'value' => $application->funding_setup_status,
                    'color' => config('status.funding_setup.' . $application->funding_setup_status, 'gray'),
                ],
            ]
        ], 200);
    }

    /**
     * A later round opens after the round before it closes. Rounds can be created at any time;
     * this only constrains the dates they are given.
     */
    private function assertOpensAfterPreviousRound(int $programId, int $roundNumber, $openDate): void
    {
        if ($roundNumber < 2 || ! $openDate) {
            return;
        }

        $previous = ProgramRound::where('program_id', $programId)->where('round_number', $roundNumber - 1)->first();
        if (! $previous || ! $previous->close_date) {
            return;
        }

        $previousClose = $previous->close_date->copy()->startOfDay();
        if (\Carbon\Carbon::parse($openDate)->startOfDay()->lessThanOrEqualTo($previousClose)) {
            throw ValidationException::withMessages([
                'open_date' => ['Round ' . $roundNumber . ' must open after Round ' . ($roundNumber - 1) . ' closes on ' . $previousClose->format('j M Y') . '.'],
            ]);
        }
    }

    public function store(Request $request, Program $program)
    {
        $userId = auth()->id();

        // Authorization
        if (!\App\Service\Program\ProgramAccess::owns($program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'round_name' => 'required|string|max:100',
                'open_date' => 'required|date',
                'close_date' => 'required|date|after:open_date',
                'review_period_end' => 'nullable|date|after:close_date',
                'announcement_date' => 'nullable|date|after:close_date',

                // Scoring rubric
                'rubric_mode' => 'nullable|in:weighted,simple_total,pass_fail',
                'scoring_criteria' => 'required_if:rubric_mode,weighted|array',

                // Question
                'round_questions' => 'nullable|array',
                'round_questions.*.question_text' => 'nullable|string|max:255',
                'round_questions.*.question_type' => 'nullable|in:short_answer,long_text',

                'knockout_questions' => 'nullable|array',
                'knockout_questions.*.text' => 'required|string|max:500',
                'knockout_questions.*.disqualify_if' => 'required|string|max:100',

                'required_documents' => 'nullable|array',

                // Reviewer settings
                'assignment_type' => 'nullable|in:owner_only,internal,external',
                'assignment_method' => 'nullable|in:manual,round_robin,load_balanced',
                'min_reviewers_required' => 'nullable|integer|min:1',

                // Advancement settings
                'advancement_mode' => 'nullable|in:manual,score_threshold,fixed_quota',
                'score_threshold' => 'nullable|numeric|min:0|max:100',
                'max_advancing' => 'nullable|integer|min:1',
                'tie_breaker_rule' => 'nullable|in:allow_over_cap,secondary_metric,manual',
            ]);

            // Check if round number already exists
            $rounds = ProgramRound::where('program_id', $program->id)->count();
            $max_rounds = $program->total_rounds;
            if ($max_rounds && $rounds >= $max_rounds) {
                throw ValidationException::withMessages([
                    'round_number' => ['Maximum number of rounds reached for this program']
                ]);
            }

            $validated['program_id'] = $program->id;
            $validated['round_number'] = $rounds ? $rounds + 1 : 1;
            $this->assertOpensAfterPreviousRound($program->id, $validated['round_number'], $validated['open_date']);
            $validated['rubric_mode'] = $validated['rubric_mode'] ?? 'weighted';
            $validated['assignment_type'] = $validated['assignment_type'] ?? 'owner_only';
            $validated['assignment_method'] = $validated['assignment_method'] ?? 'manual';
            $validated['advancement_mode'] = $validated['advancement_mode'] ?? 'manual';
            $validated['status'] = 'draft';

            $round = ProgramRound::create($validated);

            if ($request->has('round_questions') || $request->has('knockout_questions')) {
                RoundCustomQuestion::where('round_id', $round->id)->delete();
            }

            // Normal questions
            foreach ($validated['round_questions'] ?? [] as $q) {
                $questionsToInsert[] = [
                    'round_id' => $round->id,
                    'question_text' => $q['question_text'] ?? '',
                    'question_type' => $q['question_type'],
                    'is_required' => true,
                    'created_at' => now(),
                ];
            }

            // Knockout questions
            foreach ($validated['knockout_questions'] ?? [] as $q) {
                $questionsToInsert[] = [
                    'round_id' => $round->id,
                    'question_text' => $q['text'] ?? '',
                    'question_type' => 'knockout',
                    'is_required' => true,
                    'knockout_fail_value' => $q['disqualify_if'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Single DB query instead of many
            if (!empty($questionsToInsert)) {
                RoundCustomQuestion::insert($questionsToInsert);
            }

            if($max_rounds == $validated['round_number']){
                $program->status = 'published';
                $program->save();
            }

            DB::commit();
            return response()->json([
                'message' => 'Round created successfully',
                'data' => $round,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.', 'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Update round
     * PATCH /api/v1/program/rounds/{round}
     */
    public function update(Request $request, ProgramRound $round)
    {
        $userId = auth()->id();

        // Authorization
        if (!\App\Service\Program\ProgramAccess::owns($round->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'round_name' => 'sometimes|required|string|max:100',
                'open_date' => 'sometimes|required|date',
                'close_date' => 'sometimes|required|date',
                'review_period_end' => 'nullable|date',
                'announcement_date' => 'nullable|date',
                'rubric_mode' => 'sometimes|in:weighted,simple_total,pass_fail',
                'scoring_criteria' => 'required_if:rubric_mode,weighted|array',

                // Question
                'round_questions' => 'nullable|array',
                'round_questions.*.question_text' => 'nullable|string|max:255',
                'round_questions.*.question_type' => 'nullable|in:short_answer,long_text',

                'knockout_questions' => 'nullable|array',
                'knockout_questions.*.text' => 'required|string|max:500',
                'knockout_questions.*.disqualify_if' => 'required|string|max:100',

                'required_documents' => 'nullable|array',
                'assignment_type' => 'sometimes|in:owner_only,internal,external,both',
                'assignment_method' => 'sometimes|in:manual,round_robin,load_balanced',
                'min_reviewers_required' => 'nullable|integer|min:1',
                'advancement_mode' => 'sometimes|in:manual,score_threshold,fixed_quota',
                'score_threshold' => 'nullable|numeric|min:0|max:100',
                'max_advancing' => 'nullable|integer|min:1',
                'tie_breaker_rule' => 'nullable|in:allow_over_cap,secondary_metric,manual',
            ]);

            // The status is never set from here: publishing and finalizing have their own endpoints with their own checks
            if (array_key_exists('open_date', $validated)) {
                $this->assertOpensAfterPreviousRound($round->program_id, (int) $round->round_number, $validated['open_date']);
            }

            $round->update($validated);

            // Q insert — clear existing first to prevent duplicates
            $questionsToInsert = [];

            //if (!empty($validated['round_questions']) || !empty($validated['knockout_questions'])) {
                //RoundCustomQuestion::where('round_id', $round->id)->where('question_type', 'knockout')->delete();
            //}

            // Normal questions
            foreach ($validated['round_questions'] ?? [] as $q) {
                $questionsToInsert[] = [
                    'id' => $q['id'] ?? null, // IMPORTANT
                    'round_id' => $round->id,
                    'question_text' => $q['question_text'] ?? '',
                    'question_type' => $q['question_type'],
                    'is_required' => true,
                    'knockout_fail_value' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ];
            }

            // Knockout questions
            foreach ($validated['knockout_questions'] ?? [] as $q) {
                $questionsToInsert[] = [
                    'id' => $q['id'] ?? null, // IMPORTANT
                    'round_id' => $round->id,
                    'question_text' => $q['text'] ?? '',
                    'question_type' => 'knockout',
                    'is_required' => true,
                    'knockout_fail_value' => $q['disqualify_if'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Single DB query instead of many
            if (!empty($questionsToInsert)) {
                RoundCustomQuestion::upsert(
                    $questionsToInsert,
                    ['id'], // unique key
                    [
                        'round_id',
                        'question_text',
                        'question_type',
                        'is_required',
                        'knockout_fail_value',
                        'updated_at'
                    ]
                );
            }

            DB::commit();

            // Notify if needed (e.g. if round is published or closed)

            return response()->json([
                'message' => 'Round updated successfully',
                'data' => $round,
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token'])
            ]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /**
     * Delete round
     * DELETE /api/v1/program/rounds/{round}
     */
    public function destroy(ProgramRound $round)
    {
        $userId = auth()->id();

        // Authorization
        if (!\App\Service\Program\ProgramAccess::owns($round->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Cannot delete if applications exist
        $hasApplications = ProgramApplication::where('current_round_id', $round->id)->exists();

        if ($hasApplications) {
            return response()->json([
                'error' => 'Cannot delete round with existing applications'
            ], 422);
        }

        $round->delete();

        return response()->json([
            'message' => 'Round deleted successfully',
        ]);
    }

    /**
     * Finalize round and process advancement
     * POST /api/v1/program/rounds/{round}/finalize
     */
    public function finalize(Request $request, ProgramRound $round)
    {
        $userId = auth()->id();

        if (!\App\Service\Program\ProgramAccess::owns($round->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($round->status === 'finalized') {
            return response()->json(['error' => 'Round already finalized'], 422);
        }

        // A round that was never published has nothing to finalize; publish it first
        if ($round->status === 'draft') {
            $message = 'This round is still a draft. Finish setting it up and publish it before it can be finalized.';
            return response()->json(['message' => $message, 'error' => $message], 422);
        }

        // A manual round has no scores to total up: the owner accepts or rejects each application, and the round is
        // finalized once every one has a decision.
        if (($round->advancement_mode ?? 'manual') === 'manual') {
            $undecided = ProgramApplication::where('current_round_id', $round->id)
                ->whereIn('round_status', ['submitted', 'assigned', 'under_review', 'scored', 'draft'])
                ->count();

            if ($undecided > 0) {
                $message = $undecided === 1
                    ? '1 application still needs a decision. Accept or reject it before finalizing this round.'
                    : "{$undecided} applications still need a decision. Accept or reject each one before finalizing this round.";

                return response()->json(['message' => $message, 'error' => $message, 'undecided_applications' => $undecided], 422);
            }

            if (!$this->isFinalRound($round) && !$this->nextRoundExists($round)) {
                $message = 'Create round ' . ($round->round_number + 1) . ' before finalizing this round, so the applicants who advance have somewhere to go.';
                return response()->json(['message' => $message, 'error' => $message], 422);
            }

            $round->status = 'finalized';
            $round->save();

            return response()->json([
                'message' => 'Round finalized successfully',
                'data' => ['advanced' => 0, 'not_selected' => 0],
            ]);
        }

        $unscoredCount = ProgramApplication::where('current_round_id', $round->id)
            ->where(function ($query) {
                $query->whereNull('round_status')->orWhere('round_status', '!=', 'scored');
            })->count();

        if ($unscoredCount > 0) {
            return response()->json([
                'error' => 'All applications in this round must be scored before the round can be finalized.',
                'unscored_applications' => $unscoredCount,
            ], 422);
        }

        DB::beginTransaction();
        try {
            $knockoutService = new KnockoutEvaluator();

            $applications = ProgramApplication::where('current_round_id', $round->id)
                ->where('round_status', 'scored')->get();

            foreach ($applications as $app) {
                $knockoutService->evaluate($app);
            }
            $failedKoApps = $applications->where('knockout_status', 'failed');
            $applications = $applications->where('knockout_status', '!=', 'failed');

            $advanced = []; $notSelected = [];

            // 3. Immediately handle failed apps
            foreach ($failedKoApps as $app) {
                $app->round_status = 'not_selected';
                $this->roundHistory->update($app, 'not_selected', 'failed knockout');
                $app->save();

                $notSelected[] = $app;
            }

            // Check if this is the final round
            $isFinalRound = $this->isFinalRound($round);

            // Applicants cannot advance to a round that has not been created yet
            if (!$isFinalRound && !$this->nextRoundExists($round)) {
                DB::rollBack();
                return response()->json([
                    'error' => 'Create round ' . ($round->round_number + 1) . ' before finalizing this round, so the applicants who advance have somewhere to go.',
                ], 422);
            }

            // Apply the round's rule (score threshold or top N). A tie at the cut-off follows the round's tie-breaker;
            // when the owner has not chosen one, finalizing stops so no applicant is dropped by chance.
            $selection = $this->selectByRule($round, $applications);

            if ($selection['tie'] && empty($selection['tie']['resolved'])) {
                DB::rollBack();
                $message = $this->tieMessage($selection['tie']);
                return response()->json(['message' => $message, 'error' => $message, 'tie' => $selection['tie']], 422);
            }

            $advanced = $selection['advanced'];
            $notSelected = array_merge($notSelected, $selection['not_selected']);

            $program = $round->program;
            $maxAwardees = $program->max_awardees ?? floor($program->total_program_amount / $program->funding_per_business);
            $awardedCount = 0;

            $total_applicants = $round->applications()->count();

            // Update application statuses
            $nextRound = null;

            if (!$isFinalRound) {
                $nextRound = ProgramRound::where('program_id', $round->program_id)
                    ->where('round_number', $round->round_number + 1)->first();
            }

            foreach ($advanced as $app) {
                $app->round_status = 'advanced';
                $app->assigned_reviewer_id = null;

                if($app->status == 'pending'){
                    $app->status = 'approved';
                }

                if ($isFinalRound) {
                    // NEW: Auto-award in final round

                    if ($awardedCount >= $maxAwardees) {
                        $app->round_status = 'not_selected';

                        // not_selected on a round
                        $this->roundHistory->update($app, 'not_selected', 'max limit');

                        $notSelected[] = $app;
                        $app->save();
                        continue;
                    }

                    // Calculate total from milestones
                    $totalAmount = $app->program_milestones()->sum('amount');

                    //if ($wallet->balance < $totalAmount) {$app->round_status = 'not_selected';$notSelected[] = $app;$app->save();continue;}
                    //$wallet->balance -= $totalAmount;$wallet->total_reserved += $totalAmount;

                    // Award application
                    $app->status = 'awarded';
                    $app->awarded_amount = $totalAmount;
                    //$app->awarded_at = now();

                    $awardedCount++;

                    $this->roundHistory->update($app, 'awarded', $validated['notes'] ?? 'Manually awarded');


                } else {
                    // Move to next round

                    // Close & create round history
                    $this->roundHistory->closeAndCreate(
                        $app, $nextRound, $total_applicants, 'in_progress'
                    );

                    if ($nextRound) {
                        $app->current_round_id = $nextRound->id;
                        $app->round_status = 'draft';

                        $app->average_score          = null;      // ← reset
                        $app->is_eligible_to_advance = false;     // ← reset
                        $app->knockout_status        = 'pending'; // ← reset
                    }
                }


                $app->save();
            }

            foreach ($notSelected as $app) {
                if ($app->knockout_status === 'failed') {
                    continue; // already handled
                }

                $app->round_status = 'not_selected';

                // not_selected on a round
                $this->roundHistory->update($app, 'not_selected', 'low avg score');

                $app->save();
            }

            if ($isFinalRound) {
                $program->status = 'awarded';
                $program->save();

                // Notify program owner
                $this->programNotification->send('program.awarded', [$round->program->owner], [
                    'program_title'   => $round->program->program_title,
                    'awarded_count' => $awardedCount,
                    'total_amount'  => $awardedCount * $round->program->funding_per_business,
                    'program_id'      => $round->program->id,
                ]);
            }

            // Update round status
            $round->status = 'finalized';
            $round->save();



            DB::commit();

            // ─── Trigger payment to all reviewers ──────────────────────────────
            $reviewerOrders = ReviewerOrder::where('round_id', $round->id)
                ->where('order_type', 'round_review')
                ->whereIn('work_status', ['delivered', 'approved'])
                ->where('payment_status', 'unpaid')
                ->where('fee_usd', '>', 0)
                ->get();

            foreach ($reviewerOrders as $reviewerOrder) {
                // Mark as pending — PO will be prompted to pay via frontend
                $reviewerOrder->update(['payment_status' => 'pending']);

                $this->programNotification->send('reviewer.payment_initiated', [
                    $reviewerOrder->reviewer
                ], [
                    'program_title' => $round->program->program_title,
                    'round_name'    => $round->round_name,
                    'fee'           => $reviewerOrder->fee_usd,
                    'order_id'      => $reviewerOrder->id,
                ]);
            }

            // notify
            $roundFinalize = new RoundFinalizationService();
            $roundFinalize->sendFinalizationNotifications(
                $round, $advanced, $notSelected, $nextRound, $isFinalRound, $awardedCount
            );

            return response()->json([
                'message' => 'Round finalized successfully',
                'data' => [
                    'advanced' => count($advanced),
                    'not_selected' => count($notSelected),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // ─── Finalizing: the rule, ties and a preview ────────────────────────────────

    /**
     * Who the round's rule moves on. Pure: it saves nothing, so the preview and the real finalize agree.
     * @return array{advanced: array, not_selected: array, tie: ?array}
     */
    private function selectByRule(ProgramRound $round, $applications): array
    {
        $apps = collect($applications)->values();

        if ($round->advancement_mode === 'score_threshold') {
            $passing = collect();
            $failing = [];

            foreach ($apps as $app) {
                $eligible = (float) $app->average_score >= (float) $round->score_threshold && $app->is_eligible_to_advance;
                if ($eligible) {
                    $passing->push($app);
                } else {
                    $failing[] = $app;
                }
            }

            $cap = (int) ($round->max_advancing ?? 0);
            if ($cap > 0 && $passing->count() > $cap) {
                $capped = $this->applyCap($round, $passing, $cap);
                return [
                    'advanced' => $capped['advanced'],
                    'not_selected' => array_merge($failing, $capped['not_selected']),
                    'tie' => $capped['tie'],
                ];
            }

            return ['advanced' => $passing->all(), 'not_selected' => $failing, 'tie' => null];
        }

        if ($round->advancement_mode === 'fixed_quota') {
            return $this->applyCap($round, $apps, (int) ($round->max_advancing ?: 10));
        }

        return ['advanced' => [], 'not_selected' => [], 'tie' => null];
    }

    /** Keep the best $cap applications. A tie that straddles the cut-off is resolved by the round's tie-breaker. */
    private function applyCap(ProgramRound $round, $applications, int $cap): array
    {
        $rule = $round->tie_breaker_rule ?: 'manual';

        $ranked = collect($applications)->sort(function ($a, $b) use ($rule) {
            $byScore = (float) $b->average_score <=> (float) $a->average_score;
            return ($byScore === 0 && $rule === 'secondary_metric')
                ? ((float) $b->match_score <=> (float) $a->match_score)
                : $byScore;
        })->values();

        if ($ranked->count() <= $cap) {
            return ['advanced' => $ranked->all(), 'not_selected' => [], 'tie' => null];
        }

        $cut = (float) $ranked[$cap - 1]->average_score;
        $above = $ranked->filter(fn ($a) => (float) $a->average_score > $cut)->count();
        $tied = $ranked->filter(fn ($a) => (float) $a->average_score === $cut)->values();
        $slots = $cap - $above;

        if ($tied->count() <= $slots) {
            return ['advanced' => $ranked->take($cap)->all(), 'not_selected' => $ranked->slice($cap)->values()->all(), 'tie' => null];
        }

        $tie = [
            'score' => $cut,
            'tied' => $tied->count(),
            'slots' => $slots,
            'names' => $tied->map(fn ($a) => $a->startup_name)->values()->all(),
            'resolved' => null,
        ];

        if ($rule === 'allow_over_cap') {
            $tie['resolved'] = 'Everyone tied at the cut-off moves on, so more than ' . $cap . ' advance.';
            return [
                'advanced' => $ranked->filter(fn ($a) => (float) $a->average_score >= $cut)->values()->all(),
                'not_selected' => $ranked->filter(fn ($a) => (float) $a->average_score < $cut)->values()->all(),
                'tie' => $tie,
            ];
        }

        if ($rule === 'secondary_metric') {
            $tie['resolved'] = 'Tied applicants are ranked by their AI match score.';
        }

        return ['advanced' => $ranked->take($cap)->all(), 'not_selected' => $ranked->slice($cap)->values()->all(), 'tie' => $tie];
    }

    private function tieMessage(array $tie): string
    {
        return "{$tie['tied']} applicants tie at {$tie['score']} and only {$tie['slots']} place(s) are left, so the round cannot choose between them. "
            . 'Open the round setup and pick a tie-breaker (let all tied applicants through, or rank them by match score), or decide them yourself.';
    }

    /** The round's rule in plain words, for the owner to read before finalizing. */
    private function ruleText(ProgramRound $round, bool $isFinal): string
    {
        $where = $isFinal ? 'are awarded' : 'move to the next round';

        return match ($round->advancement_mode) {
            'score_threshold' => 'Applicants with an average of ' . ($round->score_threshold ?? 0) . ' or more ' . $where
                . ($round->max_advancing ? ", up to {$round->max_advancing}, highest scores first" : '') . '. Everyone else is not selected.',
            'fixed_quota' => 'The top ' . ($round->max_advancing ?: 10) . ' applicants by average score ' . $where . '. Everyone else is not selected.',
            default => 'You decide each application yourself. Finalizing closes the round once every application has been accepted or rejected.',
        };
    }

    /**
     * What finalizing would do, without doing it.
     * GET /api/v1/program/rounds/{round}/finalize-preview
     */
    public function finalizePreview(ProgramRound $round)
    {
        if (!\App\Service\Program\ProgramAccess::owns($round->program)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $isFinal = $this->isFinalRound($round);
        $manual = ($round->advancement_mode ?? 'manual') === 'manual';
        $blockers = [];

        if ($round->status === 'finalized') {
            $blockers[] = 'This round is already finalized.';
        } elseif ($round->status === 'draft') {
            $blockers[] = 'This round is still a draft. Publish it before it can be finalized.';
        }

        if (!$isFinal && !$this->nextRoundExists($round)) {
            $blockers[] = 'Create round ' . ($round->round_number + 1) . ' first, so the applicants who advance have somewhere to go.';
        }

        $apps = ProgramApplication::where('current_round_id', $round->id)->get();
        $person = fn ($a) => ['id' => $a->id, 'name' => $a->startup_name, 'score' => $a->average_score !== null ? round((float) $a->average_score, 1) : null];

        $advanced = []; $notSelected = []; $undecided = []; $tie = null;

        if ($manual) {
            $undecided = $apps->whereIn('round_status', ['submitted', 'assigned', 'under_review', 'scored', 'draft'])->values()->all();
            $advanced = $apps->where('round_status', 'advanced')->values()->all();
            $notSelected = $apps->where('round_status', 'not_selected')->values()->all();

            if ($undecided) {
                $count = count($undecided);
                $blockers[] = $count === 1
                    ? '1 application still needs a decision. Accept or reject it first.'
                    : "{$count} applications still need a decision. Accept or reject each one first.";
            }
        } else {
            $unscored = $apps->filter(fn ($a) => $a->round_status !== 'scored' && $a->knockout_status !== 'failed')->values();
            $failedKnockout = $apps->where('knockout_status', 'failed')->values();

            if ($unscored->isNotEmpty()) {
                $count = $unscored->count();
                $blockers[] = ($count === 1 ? '1 application has' : "{$count} applications have") . ' not been scored yet. Every application must be scored before the round can be finalized.';
                $undecided = $unscored->all();
            }

            $scored = $apps->where('round_status', 'scored')->where('knockout_status', '!=', 'failed')->values();
            $selection = $this->selectByRule($round, $scored);
            $advanced = $selection['advanced'];
            $notSelected = array_merge($failedKnockout->all(), $selection['not_selected']);
            $tie = $selection['tie'];

            if ($tie && empty($tie['resolved'])) {
                $blockers[] = $this->tieMessage($tie);
            }
        }

        return response()->json([
            'data' => [
                'mode' => $round->advancement_mode ?? 'manual',
                'rubric_mode' => $round->rubric_mode,
                'rule' => $this->ruleText($round, $isFinal),
                'is_final_round' => $isFinal,
                'destination' => $isFinal ? 'awarded' : 'round ' . ($round->round_number + 1),
                'blockers' => $blockers,
                'can_finalize' => empty($blockers),
                'advanced' => array_map($person, $advanced),
                'not_selected' => array_map($person, $notSelected),
                'undecided' => array_map($person, $undecided),
                'tie' => $tie,
            ],
        ]);
    }

    /**
     * Manually advance specific application
     * POST /api/v1/program/applications/{application}/advance
     */
    public function advanceManual(Request $request, ProgramApplication $application)
    {
        $userId = auth()->id();

        if (!\App\Service\Program\ProgramAccess::owns($application->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $round = $application->currentRound;

        if (!$round || $round->advancement_mode !== 'manual') {
            return response()->json([
                'error' => 'Round does not allow manual advancement'
            ], 422);
        }

        if ($round->status !== 'published') {
            return response()->json([
                'error' => 'Round is not published. Current status: ' . $round->status
            ], 422);
        }

        if (!in_array($application->round_status, ['draft','submitted', 'under_review', 'scored'])) {
            return response()->json([
                'error' => 'Application is not in a advanceable state. Current status: ' . $application->round_status
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'notes' => 'nullable|string',
            ]);

            $program        = $application->program;
            $isFinalRound = $this->isFinalRound($round);

            $nextRound = null;

            // Fix pending status
            if ($application->status === 'pending') {
                $application->status = 'approved';
            }

            if ($isFinalRound) {
                $awardedCount = ProgramApplication::where('program_id', $program->id)
                    ->where('status', 'awarded')->count();

                $maxAwardees = $program->max_awardees ?? floor($program->total_program_amount / $program->funding_per_business);

                if ($awardedCount >= $maxAwardees) {
                    DB::rollBack();
                    return response()->json([
                        'error' => "Cannot advance. Maximum of {$maxAwardees} businesses can be funded ({$awardedCount} already awarded)."
                    ], 422);
                }

                $application->status         = 'awarded';
                $application->awarded_amount = $application->program_milestones()->sum('amount');
                $application->round_status   = 'advanced';



                // finalize check
                $awardedCount++;

                $remainingCount = ProgramApplication::where('current_round_id', $round->id)
                    ->whereIn('round_status', ['submitted', 'under_review', 'scored', 'draft'])
                    ->count();

                $maxAwardeesReached = $awardedCount >= $maxAwardees;
                $noMoreAppsToReview = $remainingCount === 0;

                if ($maxAwardeesReached || $noMoreAppsToReview) {
                    $round->status = 'finalized';
                    $round->save();

                    $program->status = 'awarded';
                    $program->save();
                }

                // Round history for final round
                $totalApplicants = $round->applications()->count();
                $this->roundHistory->update($application, 'awarded', $validated['notes'] ?? 'Manually awarded');

            } else {
                $nextRound = ProgramRound::where('program_id', $round->program_id)
                    ->where('round_number', $round->round_number + 1)
                    ->first();

                if (!$nextRound) {
                    DB::rollBack();
                    return response()->json(['error' => 'Next round not found'], 422);
                }


                // Round history
                $totalApplicants = $round->applications()->count();
                $this->roundHistory->closeAndCreate(
                    $application, $nextRound, $totalApplicants, 'in_progress'
                );

                $application->current_round_id       = $nextRound->id;
                $application->round_status           = 'draft';
                $application->average_score          = null;
                $application->is_eligible_to_advance = false;
                $application->knockout_status        = 'pending';

                //finalizing round
                $remainingCount = ProgramApplication::where('current_round_id', $round->id)
                    ->whereIn('round_status', ['submitted', 'under_review', 'scored', 'draft'])
                    ->count();

                if($remainingCount === 0) {
                    $round->status = 'finalized';
                    $round->save();
                }
            }

            $application->save();
            DB::commit();

            // Notifications
            if ($isFinalRound) {
                $this->programNotification->send('application.awarded', [$application->user], [
                    'program_title'    => $program->program_title,
                    'amount'         => $application->awarded_amount,
                    'application_id' => $application->id,
                    'recipientName' => $application->user->first_name,
                ]);

                // to program owner
                $this->programNotification->send('program.awarded', [$application->program->owner], [
                    'program_title' => $application->program->program_title,
                    'awarded_count' => $awardedCount,
                    'total_amount'  => $awardedCount * $application->program->funding_per_business,
                    'program_id'    => $application->program->id,
                    'application_id' => $application->id,
                    'recipientName' => $application->user->first_name,
                ]);

            } else {
                $this->programNotification->send('round.advanced', [$application->user], [
                    'program_title'    => $program->program_title,
                    'round_name'     => $nextRound->round_name,
                    'application_id' => $application->id,
                    'recipientName' => $application->user->first_name,
                ]);
            }

            return response()->json([
                'message' => 'Application advanced successfully',
                'data'    => $application
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed.', 'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function rejectManual(Request $request, ProgramApplication $application)
    {
        $userId = auth()->id();

        if (!\App\Service\Program\ProgramAccess::owns($application->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $round = $application->currentRound;

        if (!$round || $round->advancement_mode !== 'manual') {
            return response()->json([
                'error' => 'Round does not allow manual rejection'
            ], 422);
        }

        if (!in_array($application->round_status, ['submitted', 'under_review', 'scored', 'draft'])) {
            return response()->json([
                'error' => 'Application is not in a rejectable state. Current status: ' . $application->round_status
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'notes' => 'nullable|string',
            ]);

            $application->round_status = 'not_selected';
            $this->roundHistory->update(
                $application, 'not_selected', $validated['notes'] ?? 'Manually rejected'
            );

            // finalize check

            $remainingCount = ProgramApplication::where('current_round_id', $round->id)
                ->whereIn('round_status', ['submitted', 'under_review', 'scored', 'draft'])
                ->count();

            $noMoreAppsToReview = $remainingCount === 0;

            if ($noMoreAppsToReview) {
                $round->status = 'finalized';
                $round->save();
            }


            $application->save();

            DB::commit();

            $this->programNotification->send('round.not_selected', [$application->user], [
                'program_title' => $round->program->program_title,
                'round_name'  => $round->round_name,
                'program_id'  => $round->program->id,
                'recipientName' => $application->user->first_name,
            ]);

            return response()->json(['message' => 'Application rejected successfully']);

        } catch (ValidationException $e) {
            DB::rollBack();
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


    // submit application to current round
    public function submitRound(Request $request, $applicationId)
    {
        $roundHelper = new RoundHelperService();
        $userId = auth()->id();

        // ✅ Request structure validation (frontend guidance)
        $request->validate([
            'round_answers' => 'nullable|array',
            'round_answers.*.question_id' => 'required_with:round_answers|integer|exists:round_custom_questions,id',
            'round_answers.*.response' => 'nullable|string|max:1000',

            'round_documents' => 'nullable|array',
            'round_documents.*.document_type' => 'required_with:round_documents|string|max:100',
            'round_documents.*.file' => 'required_with:round_documents|file|max:20480',
        ]);

        DB::beginTransaction();

        try {
            $application = ProgramApplication::with('currentRound')->findOrFail($applicationId);

            if ($application->user_id !== $userId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            if (in_array($application->round_status, ['submitted', 'not_selected'])) {
                return response()->json(['error' => 'Round cannot be submitted in current state'], 422);
            }

            if (!$application->currentRound || $application->currentRound->status !== 'published') {
                return response()->json(['error' => 'Round is not open for submissions'], 400);
            }

            // ✅ SINGLE SERVICE handles everything
            $result = $roundHelper->handleRoundSubmission($request, $application);

            // If validation fails
            if (!empty($result['errors'])) {
                return response()->json([
                    'error' => 'Round submission incomplete',
                    'validation_errors' => $result['errors'],
                    'summary' => [
                        'total_errors' => count($result['errors']),
                    ]], 422);
            }

            $application->update([
                'round_status' => 'submitted',
                'submitted_at' => now(),
            ]);

            // Update submitted_at in round history (don't change outcome)
            ApplicationRoundHistory::where('application_id', $application->id)
                ->where('round_id', $application->current_round_id)
                ->update(['submitted_at' => now()]);

            DB::commit();

            // Thank the applicant, from the organization (best effort: never fails the submission)
            try {
                $program = $application->program;
                $round = $application->currentRound;
                $this->programNotification->send('application.received', [auth()->user()], [
                    'program_id' => $application->program_id,
                    'program_title' => $program?->program_title,
                    'application_id' => $application->id,
                    'business_name' => $application->startup_name,
                    'round_name' => $round?->round_name,
                    'round_submission' => true,
                    'organization_name' => $program?->owner?->display_name ?: $program?->funder_name,
                    'announcement_date' => $round?->announcement_date?->format('j F Y'),
                    'submitted_at' => now()->format('j M Y, H:i'),
                    'recipientName' => auth()->user()->first_name ?: auth()->user()->display_name,
                ]);
            } catch (\Throwable $e) {
                ErrorLogService::report($e, ['application_id' => $application->id]);
            }

            return response()->json([
                'message' => 'Application submitted successfully',
                'data' => [
                    'application_id' => $application->id,
                    'round_id' => $application->current_round_id,
                    'round_name' => $application->currentRound->round_name,
                    'status' => 'submitted',
                ]
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Application not found'], 404);

        } catch (\Exception $e) {
            DB::rollBack(); ErrorLogService::report($e);

            return response()->json([
                'message' => 'Something went wrong while submitting round',
            ], 500);
        }
    }

}
