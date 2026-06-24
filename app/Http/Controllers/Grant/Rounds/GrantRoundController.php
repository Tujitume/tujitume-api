<?php

namespace App\Http\Controllers\Grant\Rounds;

use App\Http\Controllers\Controller;
use App\Models\ApplicationRoundHistory;
use App\Models\Grants\Grant;
use App\Models\Grants\GrantApplication;
use App\Models\Grants\GrantProfile;
use App\Models\Grants\Rounds\ApplicationRoundResponse;
use App\Models\Grants\Rounds\GrantRound;
use App\Models\Grants\Rounds\RoundCustomQuestion;
use App\Models\Grants\Rounds\RoundRequiredDocument;
use App\Service\Grant\KnockoutEvaluator;
use App\Service\Grant\RoundFinalizationService;
use App\Service\Grant\RoundHelperService;
use App\Service\Grant\RoundHistoryService;
use App\Service\Misc\ErrorLogService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrantRoundController extends Controller
{
    /**
     * Create a round
     * POST /api/v1/grant/grants/{grant}/rounds
     */

    protected $roundHistory;
    public function __construct()
    {
        $this->roundHistory = new RoundHistoryService();
        parent::__construct();
    }
    public function index(Grant $grant){
        $rounds = GrantRound::with('applications')
            ->withCount('applications')
            ->where('grant_id', $grant->id)
            ->get()->map(function($round){
                return[
                    ...$round->toArray(),
                    'status' => [
                        'value' => $round->status,
                        'color' => config('status.grant_round'. $round->status, 'gray'),
                    ],
                ];
            });

        return response()->json(['rounds' =>$rounds ], 200);
    }

    //added by owen
    public function showRound(GrantRound $round)
    {
        $userId = auth()->id();

        $roleUser = GrantProfile::where('user_id', $userId)->where('grant_owner_id', $round->grant->user_id)->first();


        if ($round->grant->user_id !== $userId && !$roleUser) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        return response()->json([
            'data' => [
                ...$round->toArray(),
                'status' => [
                    'value' => $round->status,
                    'color' => config('status.grant_round.' . $round->status, 'gray'),
                ],
                'reviewers' => $round->reviewers
            ]
        ], 200);
    }

    # get applications for a round
    public function applications($round_id)
    {
        $round = GrantRound::findOrFail($round_id);

        if ($round->status === 'finalized') {
            // Round is done - get all apps from history
            $appIds = ApplicationRoundHistory::where('round_id', $round_id)
                ->pluck('application_id');

            $applications = GrantApplication::whereIn('id', $appIds)->get();

        } else {
            // Round still active - get current apps
            $applications = GrantApplication::where('current_round_id', $round_id)->get();
        }

        // Stats always from history (accurate regardless of round state)
        $history = ApplicationRoundHistory::where('round_id', $round_id)->get();

        $stats = [
            'total'              => $applications->count(),
            'advanced_count'     => $history->where('outcome', 'advanced')->count(),
            'not_selected_count' => $history->where('outcome', 'not_selected')->count(),
            'awarded_count'      => $history->where('outcome', 'awarded')->count(),
            'in_progress_count'  => $history->where('outcome', 'in_progress')->count(),
            'reviewed_by'         => $round->reviewers,
        ];

        $mapped = $applications->map(function ($app) use ($round_id) {
            // Get this app's outcome in this specific round from history
            $roundHistory = ApplicationRoundHistory::where('application_id', $app->id)
                ->where('round_id', $round_id)
                ->first();

            return [
                ...$app->toArray(),
                'round_outcome' => $roundHistory?->outcome,      // ← what happened in THIS round
                'round_score'   => $roundHistory?->average_score, // ← score in THIS round
                'status' => [
                    'value' => $app->status,
                    'color' => config('status.grant_application.' . $app->status, 'gray'),
                ],
                'round_status' => [
                    'value' => $app->round_status,
                    'color' => config('status.grant_application_round.' . $app->round_status, 'gray'),
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
        $application = GrantApplication::with([
            'currentRound.questions',
            'currentRound.knockoutQuestions',
            'roundAnswers',
            'scores',
            'scores.reviewer',
            'roundDocuments',
        ])->findOrFail($application_id);  // ← also fixed: use findOrFail not where()->get()

        return response()->json([
            'application' => [
                ...$application->toArray(),
                'status' => [
                    'value' => $application->status,
                    'color' => config('status.grant_application.' . $application->status, 'gray'),
                ],
                'round_status' => [
                    'value' => $application->round_status,
                    'color' => config('status.grant_application_round.' . $application->round_status, 'gray'),
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

    public function store(Request $request, Grant $grant)
    {
        $userId = auth()->id();

        // Authorization
        if ($grant->user_id !== $userId) {
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
                'scoring_criteria' => 'nullable|array',

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
            $rounds = GrantRound::where('grant_id', $grant->id)->count();
            $max_rounds = $grant->total_rounds;
            if ($max_rounds && $rounds >= $max_rounds) {
                throw ValidationException::withMessages([
                    'round_number' => ['Maximum number of rounds reached for this grant']
                ]);
            }

            $validated['grant_id'] = $grant->id;
            $validated['round_number'] = $rounds ? $rounds + 1 : 1;
            $validated['rubric_mode'] = $validated['rubric_mode'] ?? 'weighted';
            $validated['assignment_type'] = $validated['assignment_type'] ?? 'owner_only';
            $validated['assignment_method'] = $validated['assignment_method'] ?? 'manual';
            $validated['advancement_mode'] = $validated['advancement_mode'] ?? 'manual';
            $validated['status'] = 'draft';

            $round = GrantRound::create($validated);

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
                $grant->status = 'published';
                $grant->save();
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
     * PATCH /api/v1/grant/rounds/{round}
     */
    public function update(Request $request, GrantRound $round)
    {
        $userId = auth()->id();

        // Authorization
        if ($round->grant->user_id !== $userId) {
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
                'scoring_criteria' => 'nullable|array',

                // Question
                'round_questions' => 'nullable|array',
                'round_questions.*.question_text' => 'nullable|string|max:255',
                'round_questions.*.question_type' => 'nullable|in:short_answer,long_text',

                'knockout_questions' => 'nullable|array',
                'knockout_questions.*.text' => 'required|string|max:500',
                'knockout_questions.*.disqualify_if' => 'required|string|max:100',

                'required_documents' => 'nullable|array',
                'assignment_type' => 'sometimes|in:owner_only,internal,external',
                'assignment_method' => 'sometimes|in:manual,round_robin,load_balanced',
                'min_reviewers_required' => 'nullable|integer|min:1',
                'advancement_mode' => 'sometimes|in:manual,score_threshold,fixed_quota',
                'score_threshold' => 'nullable|numeric|min:0|max:100',
                'max_advancing' => 'nullable|integer|min:1',
                'tie_breaker_rule' => 'nullable|in:allow_over_cap,secondary_metric,manual',
                'status' => 'sometimes|in:draft,published,closed,in_review,finalized',
            ]);

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
     * DELETE /api/v1/grant/rounds/{round}
     */
    public function destroy(GrantRound $round)
    {
        $userId = auth()->id();

        // Authorization
        if ($round->grant->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Cannot delete if applications exist
        $hasApplications = GrantApplication::where('current_round_id', $round->id)->exists();

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
     * POST /api/v1/grant/rounds/{round}/finalize
     */
    public function finalize(Request $request, GrantRound $round)
    {
        $userId = auth()->id();

        if ($round->grant->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($round->status === 'finalized') {
            return response()->json(['error' => 'Round already finalized'], 422);
        }

        DB::beginTransaction();
        try {
            $knockoutService = new KnockoutEvaluator();

            $applications = GrantApplication::where('current_round_id', $round->id)
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
            $isFinalRound = !GrantRound::where('grant_id', $round->grant_id)
                ->where('round_number', '>', $round->round_number)->exists();

            if ($round->advancement_mode === 'score_threshold') {
                // Score-based advancement
                foreach ($applications as $app) {
                    $eligible = $app->average_score >= $round->score_threshold && $app->is_eligible_to_advance;

                    if ($eligible) {
                        $advanced[] = $app;
                    } else {
                        $notSelected[] = $app;
                    }
                }

                // Apply cap if exists
                if ($round->max_advancing && count($advanced) > $round->max_advancing) {
                    // Sort by score desc
                    usort($advanced, fn($a, $b) => $b->average_score <=> $a->average_score);

                    $capped = array_splice($advanced, $round->max_advancing);
                    $notSelected = array_merge($notSelected, $capped);
                }

            } elseif ($round->advancement_mode === 'fixed_quota') {
                // Top N advance
                $sorted = $applications->sortByDesc('average_score');
                $advanced = $sorted->take($round->max_advancing ?? 10)->values()->all();
                $notSelected = $sorted->skip($round->max_advancing ?? 10)->values()->all();
            }

            $grant = $round->grant;
            $maxAwardees = $grant->max_awardees ?? floor($grant->total_grant_amount / $grant->funding_per_business);
            $awardedCount = 0;

            $total_applicants = $round->applications()->count();

            // Update application statuses
            $nextRound = null;

            if (!$isFinalRound) {
                $nextRound = GrantRound::where('grant_id', $round->grant_id)
                    ->where('round_number', $round->round_number + 1)->first();
            }

            foreach ($advanced as $app) {
                $app->round_status = 'advanced';

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
                    $totalAmount = $app->grant_milestones()->sum('amount');

                    //if ($wallet->balance < $totalAmount) {$app->round_status = 'not_selected';$notSelected[] = $app;$app->save();continue;}
                    //$wallet->balance -= $totalAmount;$wallet->total_reserved += $totalAmount;

                    // Award application
                    $app->status = 'awarded';
                    $app->awarded_amount = $totalAmount;
                    $app->awarded_at = now();

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
                $grant->status = 'awarded';
                $grant->save();

                // Notify grant owner
                $this->grantNotification->send('grant.awarded', [$round->grant->owner], [
                    'grant_title'   => $round->grant->grant_title,
                    'awarded_count' => $awardedCount,
                    'total_amount'  => $awardedCount * $round->grant->funding_per_business,
                    'grant_id'      => $round->grant->id,
                ]);
            }

            // Update round status
            $round->status = 'finalized';
            $round->save();



            DB::commit();

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

    /**
     * Manually advance specific application
     * POST /api/v1/grant/applications/{application}/advance
     */
    public function advanceManual(Request $request, GrantApplication $application)
    {
        $userId = auth()->id();

        if ($application->grant->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $round = $application->currentRound;

        if (!$round || $round->advancement_mode !== 'manual') {
            return response()->json([
                'error' => 'Round does not allow manual advancement'
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

            $grant        = $application->grant;
            $isFinalRound = !GrantRound::where('grant_id', $round->grant_id)
                ->where('round_number', '>', $round->round_number)->exists();

            $nextRound = null;

            // Fix pending status
            if ($application->status === 'pending') {
                $application->status = 'approved';
            }

            if ($isFinalRound) {
                $awardedCount = GrantApplication::where('grant_id', $grant->id)
                    ->where('status', 'awarded')->count();

                $maxAwardees = $grant->max_awardees ?? floor($grant->total_grant_amount / $grant->funding_per_business);

                if ($awardedCount >= $maxAwardees) {
                    DB::rollBack();
                    return response()->json([
                        'error' => "Cannot advance. Maximum of {$maxAwardees} businesses can be funded ({$awardedCount} already awarded)."
                    ], 422);
                }

                $application->status         = 'awarded';
                $application->awarded_amount = $application->grant_milestones()->sum('amount');
                $application->round_status   = 'advanced';



                // finalize check
                $awardedCount++;

                $remainingCount = GrantApplication::where('current_round_id', $round->id)
                    ->whereIn('round_status', ['submitted', 'under_review', 'scored', 'draft'])
                    ->count();

                $maxAwardeesReached = $awardedCount >= $maxAwardees;
                $noMoreAppsToReview = $remainingCount === 0;

                if ($maxAwardeesReached || $noMoreAppsToReview) {
                    $round->status = 'finalized';
                    $round->save();

                    $grant->status = 'awarded';
                    $grant->save();
                }

                // Round history for final round
                $totalApplicants = $round->applications()->count();
                $this->roundHistory->update($application, 'awarded', $validated['notes'] ?? 'Manually awarded');

            } else {
                $nextRound = GrantRound::where('grant_id', $round->grant_id)
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
                $remainingCount = GrantApplication::where('current_round_id', $round->id)
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
                $this->grantNotification->send('application.awarded', [$application->user], [
                    'grant_title'    => $grant->grant_title,
                    'amount'         => $application->awarded_amount,
                    'application_id' => $application->id,
                ]);

                // to grant owner
                $this->grantNotification->send('grant.awarded', [$application->grant->owner], [
                    'grant_title'   => $application->grant->grant_title,
                    'awarded_count' => $awardedCount,
                    'total_amount'  => $awardedCount * $application->grant->funding_per_business,
                    'grant_id'      => $application->grant->id,
                ]);

            } else {
                $this->grantNotification->send('round.advanced', [$application->user], [
                    'grant_title'    => $grant->grant_title,
                    'round_name'     => $nextRound->round_name,
                    'application_id' => $application->id,
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

    public function rejectManual(Request $request, GrantApplication $application)
    {
        $userId = auth()->id();

        if ($application->grant->user_id !== $userId) {
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

            $remainingCount = GrantApplication::where('current_round_id', $round->id)
                ->whereIn('round_status', ['submitted', 'under_review', 'scored', 'draft'])
                ->count();

            $noMoreAppsToReview = $remainingCount === 0;

            if ($noMoreAppsToReview) {
                $round->status = 'finalized';
                $round->save();
            }


            $application->save();

            DB::commit();

            $this->grantNotification->send('round.not_selected', [$application->user], [
                'grant_title' => $round->grant->grant_title,
                'round_name'  => $round->round_name,
                'grant_id'  => $round->grant->id,
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
            $application = GrantApplication::with('currentRound')->findOrFail($applicationId);

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

            return response()->json([
                'message' => 'Round submitted successfully',
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
