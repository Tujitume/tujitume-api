<?php

namespace App\Http\Controllers\Program\Rounds;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundReviewer;
use App\Models\Programs\ProgramApplication;

use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Models\ReviewerOrder;
use App\Service\Account\RegisterService;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;


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
                    'name' => $user->first_name.' '.$user->last_name,
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
                'fee_type'            => 'nullable|in:flat,per_application',
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
                    'fee_type'            => $validated['fee_type'] ?? 'flat',
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
                    'fee_type'            => $validated['fee_type'] ?? 'flat',
                    'fee_currency'        => $validated['fee_currency'] ?? 'USD',
                    'acceptance_status'   => 'pending',
                ]);

            }

            $roundReviewer = RoundReviewer::where('round_id', $round->id)->where('user_id', $userId)->firstOrFail();
            // One round order represents this reviewer's complete round workload.
            ReviewerOrder::create([
                'organization_id' => $round->program->organization_id,
                'reviewer_id'     => $userId,
                'program_id'      => $round->program_id,
                'order_type'      => 'round_review',
                'round_id'        => $round->id,
                'round_reviewer_id' => $roundReviewer->id,
                'fee_usd'         => ($validated['fee_type'] ?? 'flat') === 'per_application'
                    ? 0 : ($validated['reviewer_fee'] ?? 0),

                'fee_type'        => $validated['fee_type'] ?? 'per_application',
                'work_status'     => 'assigned',
                'acceptance_status' => 'pending',
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


    // Reviewer fetch assignments requests for a round (to accept or decline)
    public function myAssignedRequests()
    {
        $userId = auth()->id();

        $assignments = RoundReviewer::where('user_id', $userId)
            ->with([
                'programRound',
                'reviewerOrder'
            ])
            ->get();

        return response()->json(['assignment_requests' => $assignments], 200);
    }

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
        if ($pivot->acceptance_status !== 'pending') {
            return response()->json(['error' => 'This assignment is no longer awaiting a response.'], 422);
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

            $order = ReviewerOrder::where('round_reviewer_id', $pivot->id)->lockForUpdate()->first();
            if (!$order) {
                throw new \RuntimeException('Reviewer order not found for this round assignment.');
            }
            $order->update(['acceptance_status' => 'accepted', 'accepted_at' => now()]);

            // Trigger app assignment based on method
            $this->assignApplicationsToReviewer($round, $userId);

            // Load balanced assignment can change existing reviewers' application counts too.
            ReviewerOrder::where('round_id', $round->id)->where('order_type', 'round_review')
                ->get()->each->refreshRoundReviewFee();

            // Calaculate total reviewer fees and check wallet balance
            $totalReviewerFees = ReviewerOrder::where('round_id', $round->id)
                ->where('order_type', 'round_review')->where('acceptance_status', 'accepted')
                ->where('payment_status', '!=', 'completed')->sum('fee_usd');

            $wallet    = $round->program->wallet;
            $available = $wallet?->balance ?? 0;
            $required  = $totalReviewerFees;
            $sufficient = $available >= $required;


            DB::commit();

            if(!$sufficient) {
                $this->programNotification->send('reviewer.accepted_insufficient_funds', [
                    $round->program->owner,
                ], [
                    'program_id'    => $round->program_id,
                    'program_title' => $round->program->program_title,
                    'round_name'    => $round->round_name,
                    'required'      => $required,
                    'available'     => $available,
                    'shortfall'     => $required - $available,
                    'currency'      => 'USD', //KES
                ]);
            }

            // Let the program owner know the reviewer is now available to work.
            $this->programNotification->send('reviewer.accepted', [
                $round->program->owner,
            ], [
                'program_id'    => $round->program_id,
                'program_title' => $round->program->program_title,
                'round_name'    => $round->round_name,
                'reviewer_name' => auth()->user()->first_name . ' ' . auth()->user()->last_name,
            ]);

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

        $pivot = RoundReviewer::where('round_id', $round->id)->where('user_id', $userId)->first();

        if (!$pivot) {
            return response()->json(['error' => 'Not assigned to this round'], 404);
        }

        if ($pivot->acceptance_status !== 'pending') {
            return response()->json(['error' => 'This assignment is no longer awaiting a response.'], 422);
        }

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

        $pivot?->reviewerOrder?->update(['acceptance_status' => 'declined']);

        // Notify the program owner that another reviewer may need to be assigned.
        $this->programNotification->send('reviewer.declined', [
            $round->program->owner
        ], [
            'program_id'    => $round->program_id,
            'program_title' => $round->program->program_title,
            'round_name'    => $round->round_name,
            'reviewer_name' => auth()->user()->first_name . ' ' . auth()->user()->last_name,
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

            $isRoundReviewer = DB::table('round_reviewers')
                ->where('round_id', $round->id)
                ->where('user_id', $reviewer->id)
                ->first();

            if (! $isRoundReviewer) {
                return response()->json(['error' => 'The selected user is not assigned as a reviewer for this round.'], 422);
            }

            if ($isRoundReviewer->acceptance_status !== 'accepted') {
                return response()->json(['error' => 'Reviewer must accept the assignment before applications can be assigned.'], 422);
            }

            $applicationIds = collect($validated['application_ids'])->unique()->values();

            $applicationsBelongToRound = ProgramApplication::whereIn('id', $applicationIds)
                ->where('current_round_id', $round->id)
                ->count() === $applicationIds->count();

            if (! $applicationsBelongToRound) {
                return response()->json(['error' => 'Each application must belong to this round.'], 422);
            }

            DB::transaction(function () use ($applicationIds, $round, $reviewer) {
                foreach ($applicationIds as $appId) {
                    // One reviewer owns an application's assignment for a round.
                    ReviewerApplicationAssignment::where('round_id', $round->id)
                        ->where('application_id', $appId)
                        ->delete();

                    $assignment = ReviewerApplicationAssignment::create([
                        'round_id'       => $round->id,
                        'reviewer_id'    => $reviewer->id,
                        'reviewer_order_id' => ReviewerOrder::where('round_id', $round->id)->where('reviewer_id', $reviewer->id)->value('id'),
                        'application_id' => $appId,
                        'status'         => 'assigned',
                        'assigned_at'    => now(),
                    ]);

                    $assignment->application->round_status = 'assigned';
                    $assignment->application->save();
                }

                ReviewerOrder::where('round_id', $round->id)->where('order_type', 'round_review')
                    ->get()->each->refreshRoundReviewFee();
            });

            $assigned = $applicationIds->count();

            // This existing event sends both the in-app notification and email.
            $this->programNotification->send('round.scoring_assigned', [$reviewer], [
                'program_id'    => $round->program_id,
                'program_title' => $round->program->program_title,
                'round_name'    => $round->round_name,
                'max_apps'      => $assigned,
            ]);

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
        $user = Auth::user();

        $userId = $user->id;

        if (!$user->stripe_connect_id && !$user->lipr_wallet) {
            throw new \RuntimeException('You must onboard to Stripe or Mpesa before starting a review.', 422);
        }

        $assignment = ReviewerApplicationAssignment::where('round_id', $round->id)
            ->where('application_id', $application->id)
            ->where('reviewer_id', $userId)
            ->first();

        if (!$assignment) {
            return response()->json(['error' => 'This application is not assigned to you'], 403);
        }

        $roundReviewer = RoundReviewer::where('round_id', $round->id)->where('user_id', $userId)->first();
        if (!$roundReviewer || $roundReviewer->acceptance_status !== 'accepted') {
            return response()->json(['error' => 'Accept the round assignment before starting review.'], 422);
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

        $assignment->reviewerOrder()->update(['work_status' => 'in_progress']);

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
                $query->whereIn('round_status', ['pending', 'assigned', 'submitted', 'under_review', 'scored']);
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
    public function myAssignedApplications()
    {
        $userId = auth()->id();

        $assignments = ReviewerApplicationAssignment::where('reviewer_id', $userId)
            ->with([
                'application.roundAnswers',
                'application.roundDocuments',
                'application.scores' => function ($query) use ($userId) {
                    $query->where('reviewer_id', $userId);
                },
            ])
            ->get();

            // ->map(fn($a) => [
            //     ...$a->application->toArray(),
            //     //'review_status' => $a->status,
            //     'started_at'    => $a->started_at,
            //     'completed_at'  => $a->completed_at,
            // ]);

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
        $totalReviewerFees = ReviewerOrder::where('round_id', $round->id)
            ->where('order_type', 'round_review')
            ->where('acceptance_status', 'accepted')
            ->where('payment_status', '!=', 'completed')
            ->where('work_status', '!=', 'rejected')
            ->sum('fee_usd');

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
