<?php

namespace App\Http\Controllers\Program\Rounds;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundReviewer;
use App\Models\ReviewerOrder;
use App\Service\Account\RegisterService;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Calculation\MathTrig\Round;

class RoundReviewerController extends Controller
{
    // added by owen
    /**
     * List reviewers for a round
     * GET /api/v1/program/rounds/{round}/reviewers
     */
    public function index(ProgramRound $round)
    {
        $userId = auth()->id();

        if ($round->program->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $reviewers = $round->reviewers()
            ->withPivot(['reviewer_type', 'max_apps_assigned', 'expertise_tags'])
            ->get()
            ->map(function ($user) {
                return [
                    'user_id' => $user->id,
                    'name' => $user->fname.' '.$user->lname,
                    'email' => $user->email,
                    'image' => $user->image,
                    'reviewer_type' => $user->pivot->reviewer_type,
                    'max_apps_assigned' => $user->pivot->max_apps_assigned,
                    'expertise_tags' => $user->pivot->expertise_tags
                        ? json_decode($user->pivot->expertise_tags, true)
                        : [],
                ];
            });

        return response()->json(['data' => $reviewers], 200);
    }

    // public function rounds()
    // {
    //     try {
    //         $userId = auth()->id();

    //         $assignments = RoundReviewer::where('user_id', $userId)
    //             ->pluck('round_id')
    //             ->toArray();

    //         $rounds = ProgramRound::with([
    //             'questions',
    //             'applications' => function ($query) {
    //                 $query->whereIn('round_status', ['pending', 'under_review', 'scored']);
    //             },
    //             'applications.roundAnswers',
    //             'applications.roundDocuments',
    //             'applications.scores' => function ($query) use ($userId) {
    //                 $query->where('reviewer_id', $userId);
    //             },
    //         ])->whereIn('id', $assignments)->get();

    //         return response()->json(['rounds' => $rounds], 200);

    //     } catch (\Exception $e) {
    //         ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);

    //         return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
    //     }
    // }

    /**
     * Assign reviewer to round
     * POST /api/v1/program/rounds/{round}/reviewers
     */
    public function store(Request $request, ProgramRound $round)
    {
        $userId = auth()->id();

        if ($round->program->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Block assignment_method if owner_only
        if ($round->assignment_type === 'owner_only') {
            return response()->json([
                'error' => 'Round is owner_only — no external reviewers can be assigned.'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'reviewer_type'       => 'required|in:internal,external',
                'user_id'             => 'required|exists:users,id',
                'max_apps_assigned'   => 'nullable|integer|min:1',
                'assigned_percentage' => 'nullable|integer|min:1|max:100',
                'expertise_tags'      => 'nullable|array',
                'reviewer_fee'        => 'nullable|numeric|min:0',
                'fee_currency'        => 'nullable|string|max:10',
            ]);

            $userId = $validated['user_id'];

            // Validate: manual mode requires percentage
            if ($round->assignment_method === 'manual' && empty($validated['assigned_percentage'])) {
                // return response()->json([
                //     'error' => 'assigned_percentage is required for manual assignment method.'
                // ], 422);
            }

            // Validate: total percentages don't exceed 100 for manual mode
            if ($round->assignment_method === 'manual' && !empty($validated['assigned_percentage'])) {
                $existingPct = DB::table('round_reviewers')
                    ->where('round_id', $round->id)
                    ->sum('assigned_percentage');

                if ($existingPct + $validated['assigned_percentage'] > 100) {
                    return response()->json([
                        'error' => "Total assigned percentage would exceed 100%. Currently {$existingPct}% assigned."
                    ], 422);
                }
            }

            $user = User::find($validated['user_id']);

            if ($request->reviewer_type == 'internal') {

                $user->load('organizationRole');

                // Check if already assigned
                $exists = $round->reviewers()->where('user_id', $userId)->exists();

                if ($exists) {
                    throw ValidationException::withMessages([
                        'user_id' => ['Reviewer already assigned to this round'],
                    ]);
                }

                $round->reviewers()->attach($userId, [
                    'reviewer_type'       => 'internal',
                    'max_apps_assigned'   => $validated['max_apps_assigned'] ?? null,
                    'assigned_percentage' => $validated['assigned_percentage'] ?? null,
                    'expertise_tags'      => isset($validated['expertise_tags']) ? json_encode($validated['expertise_tags']) : null,
                    'reviewer_fee'        => $validated['reviewer_fee'] ?? null,
                    'fee_currency'        => $validated['fee_currency'] ?? 'USD',
                    'acceptance_status'   => 'pending',
                ]);

            } elseif ($request->reviewer_type == 'external') {

                $user->load('organization');
                $round->reviewers()->attach($user->id, [
                    'reviewer_type'       => 'external',
                    'max_apps_assigned'   => $validated['max_apps_assigned'] ?? null,
                    'assigned_percentage' => $validated['assigned_percentage'] ?? null,
                    'expertise_tags'      => isset($validated['expertise_tags'])
                        ? json_encode($validated['expertise_tags']) : null,
                    'reviewer_fee'        => $validated['reviewer_fee'] ?? null,
                    'fee_currency'        => $validated['fee_currency'] ?? 'USD',
                    'acceptance_status'   => 'pending',
                ]);

            }

            // Create ReviewerOrder
            ReviewerOrder::create([
                'organization_id' => $round->program->organization_id,
                'reviewer_id'     => $userId,
                'program_id'      => $round->program_id,
                'order_type'      => 'round_review',
                'round_id'        => $round->id,
                'fee_usd'         => $validated['reviewer_fee'] ?? 10,
                'work_status'     => 'assigned',
                'payment_status'  => 'unpaid',
                'deadline'        => $round->close_date ?? null,
            ]);

            DB::commit();

            // Notify reviewer of assignment
            $this->programNotification->send('round.scoring_assigned', [$user], [
                'program_title' => $round->program->program_title,
                'round_name' => $round->round_name,
                'max_apps' => $validated['max_apps_assigned'] ?? null,
                'expertise_tags' => $validated['expertise_tags'] ?? [],
                'program_id' => $round->program_id,
                'proposed_fee' => $validated['reviewer_fee'] ?? null,
                'recipientName' => $user->first_name ?? $user->display_name,
            ]);


            return response()->json([
                'message' => 'Reviewer assigned successfully',
                'data'  => $user
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);

            return response()->json(['message' => 'Something went wrong.'], 500);
        }
    }

    /**
     * Remove reviewer from round
     * DELETE /api/v1/program/rounds/{round}/reviewers/{user}
     */
    public function destroy(ProgramRound $round, User $user)
    {
        $userId = auth()->id();

        if ($round->program->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $round->reviewers()->detach($user->id);

        return response()->json(['message' => 'Reviewer removed successfully']);
    }

    // ____________________________________________Complex Methods___________________________________________________

    // ─── Reviewer accepts assignment ─────────────────────────────
    // POST /programs/rounds/{round}/reviewers/accept
    public function accept(ProgramRound $round)
    {
        $userId = auth()->id();

        $pivot = DB::table('round_reviewers')
            ->where('round_id', $round->id)
            ->where('user_id', $userId)
            ->first();

        if (!$pivot) {
            return response()->json(['error' => 'You are not assigned to this round'], 404);
        }

        if ($pivot->acceptance_status === 'accepted') {
            return response()->json(['error' => 'Already accepted'], 422);
        }

        DB::beginTransaction();
        try {
            // Get current accepted count for queue position
            $queuePosition = DB::table('round_reviewers')
                ->where('round_id', $round->id)
                ->where('acceptance_status', 'accepted')
                ->count() + 1;

            DB::table('round_reviewers')
                ->where('round_id', $round->id)
                ->where('user_id', $userId)
                ->update([
                    'acceptance_status' => 'accepted',
                    'accepted_at'       => now(),
                    'queue_position'    => $queuePosition,
                ]);

            // Trigger app assignment based on method
            $this->assignApplicationsToReviewer($round, $userId);

            DB::commit();

            return response()->json([
                'message'        => 'Assignment accepted.',
                'queue_position' => $queuePosition,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => []]);
            return response()->json(['message' => 'Something went wrong.'], 500);
        }
    }

    // ─── Reviewer declines assignment ────────────────────────────
    // POST /programs/rounds/{round}/reviewers/decline
    public function decline(ProgramRound $round)
    {
        $userId = auth()->id();

        $exists = DB::table('round_reviewers')
            ->where('round_id', $round->id)
            ->where('user_id', $userId)
            ->exists();

        if (!$exists) {
            return response()->json(['error' => 'Not assigned to this round'], 404);
        }

        DB::table('round_reviewers')
            ->where('round_id', $round->id)
            ->where('user_id', $userId)
            ->update(['acceptance_status' => 'declined']);

        // Notify program owner
        $this->grantNotification->send('reviewer.declined', [
            $round->program->owner
        ], [
            'program_title' => $round->program->program_title,
            'round_name'    => $round->round_name,
            'reviewer_name' => auth()->user()->first_name,
        ]);

        return response()->json(['message' => 'Assignment declined.'], 200);
    }

    // ─── PO manually assigns specific apps to reviewer ───────────
    // POST /programs/rounds/{round}/reviewers/{reviewer}/assign-applications
    public function assignApplications(Request $request, ProgramRound $round, User $reviewer)
    {
        if ($round->program->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($round->assignment_method !== 'manual') {
            return response()->json([
                'error' => 'Manual application assignment is only available in manual assignment method.'
            ], 422);
        }

        try {
            $validated = $request->validate([
                'application_ids'   => 'required|array|min:1',
                'application_ids.*' => 'integer|exists:program_applications,id',
            ]);

            $assigned = 0;
            foreach ($validated['application_ids'] as $appId) {
                // Remove existing assignment for this app in this round
                ReviewerApplicationAssignment::where('round_id', $round->id)
                    ->where('application_id', $appId)
                    ->delete();

                ReviewerApplicationAssignment::create([
                    'round_id'       => $round->id,
                    'reviewer_id'    => $reviewer->id,
                    'application_id' => $appId,
                    'status'         => 'assigned',
                    'assigned_at'    => now(),
                ]);
                $assigned++;
            }

            return response()->json([
                'message'  => "{$assigned} application(s) assigned to reviewer.",
                'assigned' => $assigned,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong.'], 500);
        }
    }

    // ─── Reviewer starts reviewing an application ─────────────────
    // POST /programs/rounds/{round}/applications/{application}/start-review
    public function startReview(ProgramRound $round, ProgramApplication $application)
    {
        $userId = auth()->id();

        $assignment = ReviewerApplicationAssignment::where('round_id', $round->id)
            ->where('application_id', $application->id)
            ->where('reviewer_id', $userId)
            ->first();

        if (!$assignment) {
            return response()->json(['error' => 'This application is not assigned to you'], 403);
        }

        if ($assignment->status === 'completed') {
            return response()->json(['error' => 'Already reviewed'], 422);
        }

        // ─── WALLET FUNDS CHECK ───────────────────────────────────
        $walletCheck = $this->checkWalletFundsForReviewers($round);
        if (!$walletCheck['sufficient']) {
            return response()->json([
                'error'     => 'Insufficient program wallet funds to cover reviewer fees for this round.',
                'required'  => $walletCheck['required'],
                'available' => $walletCheck['available'],
                'shortfall' => $walletCheck['shortfall'],
            ], 422);
        }

        $assignment->update([
            'status'     => 'in_review',
            'started_at' => now(),
        ]);

        // Mark reviewer as started in pivot
        DB::table('round_reviewers')
            ->where('round_id', $round->id)
            ->where('user_id', $userId)
            ->whereNull('review_started_at')
            ->update(['review_started_at' => now()]);

        return response()->json([
            'message' => 'Review started.',
            'data'    => $assignment,
        ], 200);
    }

    // ─── Get reviewer's round list ────────────────────────────────
    // GET /programs/reviewer/assigned-rounds
    public function rounds()
    {
        $userId     = auth()->id();
        $roundIds   = DB::table('round_reviewers')
            ->where('user_id', $userId)
            ->where('acceptance_status', 'accepted')
            ->pluck('round_id')
            ->toArray();

        $rounds = ProgramRound::with([
            'questions',
            'applications' => function ($query) {
                $query->whereIn('round_status', ['pending','submitted', 'under_review', 'scored']);
            },
            'applications.roundAnswers',
            'applications.roundDocuments',
            'applications.scores' => function ($query) use ($userId) {
                $query->where('reviewer_id', $userId);
            },
        ])->whereIn('id', $roundIds)->get();

        return response()->json(['rounds' => $rounds], 200);
    }

    // ─── Get applications assigned to reviewer for a round ────────
    // GET /programs/rounds/{round}/my-applications
    public function myAssignedApplications(ProgramRound $round)
    {
        $userId = auth()->id();

        $assignments = ReviewerApplicationAssignment::where('round_id', $round->id)
            ->where('reviewer_id', $userId)
            ->with(['application'])
            ->get()
            ->map(fn($a) => [
                ...$a->application->toArray(),
                'review_status' => $a->status,
                'started_at'    => $a->started_at,
                'completed_at'  => $a->completed_at,
            ]);

        return response()->json(['data' => $assignments], 200);
    }

    // ─── PRIVATE: Assign applications based on method ─────────────
    private function assignApplicationsToReviewer(ProgramRound $round, int $reviewerId): void
    {
        $method = $round->assignment_method;

        if ($method === 'manual') {
            // Manual: PO assigns via assignApplications() — nothing auto here
            return;
        }

        $apps = ProgramApplication::where('current_round_id', $round->id)
            ->whereIn('round_status', ['submitted', 'under_review'])
            ->get();

        if ($apps->isEmpty()) return;

        $acceptedReviewers = DB::table('round_reviewers')
            ->where('round_id', $round->id)
            ->where('acceptance_status', 'accepted')
            ->orderBy('queue_position')
            ->get();

        if ($method === 'round_robin') {
            $this->assignRoundRobin($round, $reviewerId, $apps, $acceptedReviewers);
        } elseif ($method === 'load_balanced') {
            $this->assignLoadBalanced($round, $apps, $acceptedReviewers);
        }
    }

    private function assignRoundRobin(ProgramRound $round, int $newReviewerId, $apps, $reviewers): void
    {
        // Find which apps are already assigned
        $assignedAppIds = ReviewerApplicationAssignment::where('round_id', $round->id)
            ->pluck('application_id')
            ->toArray();

        $unassignedApps = $apps->whereNotIn('id', $assignedAppIds);

        // New reviewer gets all currently unassigned apps
        // (they joined the queue — from now on new apps route to them first)
        foreach ($unassignedApps as $app) {
            ReviewerApplicationAssignment::create([
                'round_id'       => $round->id,
                'reviewer_id'    => $newReviewerId,
                'application_id' => $app->id,
                'status'         => 'assigned',
                'assigned_at'    => now(),
            ]);
        }
    }

    private function assignLoadBalanced(ProgramRound $round, $apps, $reviewers): void
    {
        $reviewerCount = $reviewers->count();
        if ($reviewerCount === 0) return;

        // Only redistribute apps that haven't been started yet
        $startedAppIds = ReviewerApplicationAssignment::where('round_id', $round->id)
            ->whereIn('status', ['in_review', 'completed'])
            ->pluck('application_id')
            ->toArray();

        $redistributableApps = $apps->whereNotIn('id', $startedAppIds);

        if ($redistributableApps->isEmpty()) return;

        // Delete existing unstarted assignments and redistribute evenly
        ReviewerApplicationAssignment::where('round_id', $round->id)
            ->where('status', 'assigned') // only unstarted
            ->delete();

        $appChunks = $redistributableApps->values()->chunk(
            (int) ceil($redistributableApps->count() / $reviewerCount)
        );

        foreach ($reviewers as $index => $reviewer) {
            $chunk = $appChunks[$index] ?? collect();
            foreach ($chunk as $app) {
                ReviewerApplicationAssignment::create([
                    'round_id'       => $round->id,
                    'reviewer_id'    => $reviewer->user_id,
                    'application_id' => $app->id,
                    'status'         => 'assigned',
                    'assigned_at'    => now(),
                ]);
            }
        }
    }

    // ─── PRIVATE: Wallet funds check ──────────────────────────────
    private function checkWalletFundsForReviewers(ProgramRound $round): array
    {
        $totalReviewerFees = DB::table('round_reviewers')
            ->where('round_id', $round->id)
            ->where('acceptance_status', 'accepted')
            ->sum('reviewer_fee');

        $wallet    = $round->program->wallet;
        $available = $wallet?->balance ?? 0;
        $required  = $totalReviewerFees;
        $sufficient = $available >= $required;

        return [
            'sufficient' => $sufficient,
            'required'   => $required,
            'available'  => $available,
            'shortfall'  => $sufficient ? 0 : ($required - $available),
        ];
    }

}
