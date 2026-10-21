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

        if (!\App\Service\Program\ProgramAccess::owns($round->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $reviewers = $round->reviewers()
            ->withPivot(['reviewer_type', 'max_apps_assigned', 'expertise_tags', 'reviewer_fee', 'fee_type', 'fee_currency', 'acceptance_status'])
            ->get();

        $orderThresholds = ReviewerOrder::where('round_id', $round->id)
            ->whereIn('reviewer_id', $reviewers->pluck('id'))
            ->orderBy('id')
            ->get()
            ->keyBy('reviewer_id');

        $reviewers = $reviewers->map(function ($user) use ($orderThresholds) {
            return [
                'user_id' => $user->id,
                'name' => $user->first_name.' '.$user->last_name,
                'email' => $user->email,
                'image' => $user->image,
                // A service provider is stored as an external reviewer but shown as what they are
                'reviewer_type' => (int) $user->user_type_id === 3 ? 'third_party' : $user->pivot->reviewer_type,
                'max_apps_assigned' => $user->pivot->max_apps_assigned,
                'minimum_apps_to_get_paid' => $orderThresholds->get($user->id)?->minimumPaidApplications() ?: null,
                // How this reviewer is paid, as the round setup form names it
                'reviewer_fee' => $user->pivot->reviewer_fee,
                'fee_type' => $user->pivot->fee_type,
                'fee_currency' => $user->pivot->fee_currency,
                'reviewer_payment_basis' => $user->pivot->fee_type === 'per_application' ? 'per_application' : 'flat_round_fee',
                'acceptance_status' => $user->pivot->acceptance_status,
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

        if (!\App\Service\Program\ProgramAccess::owns($round->program)) {
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
                'reviewer_type'       => 'required|in:internal,external,third_party',
                'user_id'             => 'required|exists:users,id',
                'max_apps_assigned'   => 'nullable|integer|min:1',
                'assigned_percentage' => 'nullable|integer|min:1|max:100',
                'expertise_tags'      => 'nullable|array',
                'reviewer_fee'        => 'nullable|numeric|min:0',
                'fee_type'            => 'nullable|in:flat,per_application',
                'fee_currency'        => 'nullable|string|max:10',
                // What the round setup form sends ("Per application reviewed" / "Flat round fee")
                'reviewer_payment_basis' => 'nullable|in:per_application,flat_round_fee',
                'minimum_apps_to_get_paid' => 'nullable|integer|min:1',
                'reviewer_payment_max_applications' => 'nullable|integer|min:1',
            ]);

            $minimumPaidApps = $validated['minimum_apps_to_get_paid']
                ?? $validated['reviewer_payment_max_applications']
                ?? null;

            $fundingEnabled = filter_var($request->input('reviewer_payment_enabled', false), FILTER_VALIDATE_BOOLEAN)
                || (float) ($validated['reviewer_fee'] ?? 0) > 0;

            // The form's payment basis decides the fee type; before this it was ignored and every reviewer became "flat"
            $feeType = match ($validated['reviewer_payment_basis'] ?? null) {
                'per_application' => 'per_application',
                'flat_round_fee'  => 'flat',
                default           => $validated['fee_type'] ?? 'flat',
            };

            // A flat round fee is only earned by meeting the minimum, so it must be set. A per-application rate pays for
            // every application the reviewer scores, so the minimum is optional and one scored application qualifies.
            if (($fundingEnabled || $request->reviewer_type === 'third_party') && empty($minimumPaidApps)) {
                if ($feeType === 'flat') {
                    throw ValidationException::withMessages([
                        'minimum_apps_to_get_paid' => ['Set the minimum number of applications the reviewer must score to qualify for the flat round fee.'],
                    ]);
                }
                $minimumPaidApps = 1;
            }

            if (!empty($validated['max_apps_assigned'])
                && !empty($minimumPaidApps)
                && $minimumPaidApps > $validated['max_apps_assigned']) {
                throw ValidationException::withMessages([
                    'minimum_apps_to_get_paid' => ['The minimum paid application count cannot exceed the reviewer assignment limit.'],
                ]);
            }

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

            // A Tujitume service provider (third party) is paid for the work, so the fee is required, and they must
            // be one the organization added in Reviewer Management.
            if ($request->reviewer_type === 'third_party') {
                if ((float) ($validated['reviewer_fee'] ?? 0) <= 0) {
                    throw ValidationException::withMessages([
                        'reviewer_fee' => ['A third-party provider is paid for their work. Set a fee greater than zero.'],
                    ]);
                }

                if ((int) $user->user_type_id !== 3 || ! \App\Models\Organizations\OrganizationProvider::has((int) $round->program->organization_id, (int) $user->id)) {
                    throw ValidationException::withMessages([
                        'user_id' => ['Add this service provider to your list in Reviewer Management first.'],
                    ]);
                }

                if ($round->reviewers()->where('user_id', $userId)->exists()) {
                    throw ValidationException::withMessages([
                        'user_id' => ['This provider is already assigned to this round'],
                    ]);
                }
            }

            if ($request->reviewer_type == 'internal') {
                $feeType = 'flat';

                $user->load('organizationRole');

                // A deactivated reviewer cannot be given new work until they are activated again
                if ($user->organizationRole?->status === 'revoked') {
                    throw ValidationException::withMessages([
                        'user_id' => ['This reviewer is deactivated. Activate them from Reviewer Management first.'],
                    ]);
                }

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
                    'reviewer_fee'        => 0,
                    'fee_type'            => $feeType,
                    'fee_currency'        => $validated['fee_currency'] ?? ($round->program->currency ?: config('app.currency', 'KES')),
                    'acceptance_status'   => 'pending',
                ]);

            } elseif (in_array($request->reviewer_type, ['external', 'third_party'], true)) {

                $user->load('organization');
                $round->reviewers()->attach($user->id, [
                    'reviewer_type'       => 'external',
                    'max_apps_assigned'   => $validated['max_apps_assigned'] ?? null,
                    'assigned_percentage' => $validated['assigned_percentage'] ?? null,
                    'expertise_tags'      => isset($validated['expertise_tags'])
                        ? json_encode($validated['expertise_tags']) : null,
                    'reviewer_fee'        => $validated['reviewer_fee'] ?? null,
                    'fee_type'            => $feeType,
                    'fee_currency'        => $validated['fee_currency'] ?? ($round->program->currency ?: config('app.currency', 'KES')),
                    'acceptance_status'   => 'pending',
                ]);

            }

            $roundReviewer = RoundReviewer::where('round_id', $round->id)->where('user_id', $userId)->firstOrFail();
            $orderFee = $roundReviewer->reviewer_type === 'internal'
                ? 0
                : ($roundReviewer->fee_type === 'per_application' ? 0 : ($roundReviewer->reviewer_fee ?? 0));
            // One round order represents this reviewer's complete round workload.
            ReviewerOrder::create([
                'organization_id' => $round->program->organization_id,
                'reviewer_id'     => $userId,
                'program_id'      => $round->program_id,
                'order_type'      => 'round_review',
                'round_id'        => $round->id,
                'round_reviewer_id' => $roundReviewer->id,
                'fee_amount' => $orderFee,

                'fee_type'        => $roundReviewer->fee_type,
                'minimum_apps_to_get_paid' => $minimumPaidApps,
                'work_status'     => 'assigned',
                'acceptance_status' => 'pending',
                'payment_status'  => 'unpaid',
                'deadline'        => $round->close_date ?? null,
                'currency'        => 'KES',
            ]);

            DB::commit();

            // Notify reviewer of assignment
            $this->programNotification->send('round.scoring_assigned', [$user], [
                'program_title' => $round->program->program_title,
                'round_name' => $round->round_name,
                'max_apps' => $validated['max_apps_assigned'] ?? null,
                'expertise_tags' => $validated['expertise_tags'] ?? [],
                'program_id' => $round->program_id,
                'proposed_fee' => $roundReviewer->reviewer_type === 'internal' ? 0 : ($validated['reviewer_fee'] ?? null),
                'fee_type' => $feeType,
                'fee_currency' => $validated['fee_currency'] ?? null,
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

        if (!\App\Service\Program\ProgramAccess::owns($round->program)) {
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
                // the program comes with the round so the request can show its name, not "Program #3"
                'programRound.program:id,program_title,currency,program_type,total_rounds',
                'reviewerOrder'
            ])
            ->get();

        return response()->json(['assignment_requests' => $assignments], 200);
    }

    // ─── Sidebar counts for the signed-in reviewer ───────────────
    // GET /programs/reviewer/assignment-counts
    public function assignmentCounts()
    {
        $userId = auth()->id();

        // Rounds that asked this person to review and are waiting for their answer
        $pendingRequests = DB::table('round_reviewers')
            ->where('user_id', $userId)
            ->where('acceptance_status', 'pending')
            ->count();

        // Applications handed to them that they have not finished scoring
        $toReview = ReviewerApplicationAssignment::where('reviewer_id', $userId)
            ->whereIn('status', ['assigned', 'in_review'])
            ->count();

        return response()->json([
            'pending_requests' => $pendingRequests,
            'to_review'        => $toReview,
            'total'            => $pendingRequests + $toReview,
        ], 200);
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
                ->where('payment_status', '!=', 'completed')->sum('fee_amount');

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
                    'currency'      => $round->program->currency ?: config('app.currency', 'KES'),
                ]);
            }

            // Let the program owner know the reviewer is now available to work.
            $this->programNotification->send('reviewer.accepted', [
                $round->program->owner,
            ], [
                'program_id'    => $round->program_id,
                'program_title' => $round->program->program_title,
                'round_name'    => $round->round_name,
                'round_id'      => $round->id,
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
            'round_id'      => $round->id,
            'reviewer_name' => auth()->user()->first_name . ' ' . auth()->user()->last_name,
        ]);

        return response()->json(['message' => 'Assignment declined.'], 200);
    }

    // ─── PO manually assigns specific apps to reviewer ───────────
    // POST /programs/rounds/{round}/reviewers/{reviewer}/assign-applications
    public function assignApplications(Request $request, ProgramRound $round, User $reviewer)
    {
        if (!\App\Service\Program\ProgramAccess::owns($round->program)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($round->assignment_method !== 'manual') {
            return response()->json([
                'error' => 'Manual application assignment is only available in manual assignment method.'
            ], 422);
        }

        if ($round->status !== 'published') {
            // Say what it means for the person, not just the status word
            $message = match ($round->status) {
                'finalized' => 'This round is finalized, so applications can no longer be assigned to reviewers. Assigning is only possible while the round is open.',
                'draft' => 'This round is not published yet. Publish it first, then you can assign applications to reviewers.',
                default => 'Applications can only be assigned to reviewers while the round is open. This round is ' . $round->status . '.',
            };

            return response()->json(['message' => $message, 'error' => $message], 422);
        }

        try {
            $validated = $request->validate([
                'application_ids'   => 'required|array|min:1',
                'application_ids.*' => 'integer|exists:program_applications,id',
            ]);

            $roundReviewer = RoundReviewer::query()->where('round_id', $round->id)
                ->where('user_id', $reviewer->id)->first();

            if (! $roundReviewer) {
                return response()->json(['error' => 'The selected user is not assigned as a reviewer for this round.'], 422);
            }

            if ($roundReviewer->acceptance_status !== 'accepted') {
                return response()->json(['error' => 'Reviewer must accept the assignment before applications can be assigned.'], 422);
            }

            $applicationIds = collect($validated['application_ids'])->unique()->values();

            $applicationsBelongToRound = ProgramApplication::whereIn('id', $applicationIds)
                ->where('current_round_id', $round->id)
                ->count() === $applicationIds->count();

            if (! $applicationsBelongToRound) {
                return response()->json(['error' => 'Each application must belong to this round.'], 422);
            }

            DB::transaction(function () use ($applicationIds, $round, $reviewer, $roundReviewer) {

                $order = ReviewerOrder::where('round_id', $round->id)
                    ->where('reviewer_id', $reviewer->id)->where('order_type', 'round_review')->latest('id')
                    ->lockForUpdate()->first();

                if (!$order || $order->work_status === 'approved') {
                    $minimumPaidApps = $order?->minimumPaidApplications() ?? 0;
                    $feeType = $roundReviewer->reviewer_type === 'internal'
                        ? 'flat'
                        : ($roundReviewer->fee_type ?? 'per_application');
                    $reviewerFee = $roundReviewer->reviewer_type === 'internal'
                        ? 0
                        : (float) ($roundReviewer->reviewer_fee ?? 0);

                    $order = ReviewerOrder::create([
                        'organization_id' => $round->program->organization_id,
                        'reviewer_id' => $reviewer->id,
                        'program_id' => $round->program_id,
                        'order_type' => 'round_review',
                        'round_id' => $round->id,
                        'round_reviewer_id' => $roundReviewer->id,
                        'fee_amount' => $feeType === 'per_application' ? 0 : $reviewerFee,
                        'fee_type' => $feeType,
                        'minimum_apps_to_get_paid' => $minimumPaidApps,
                        'work_status' => 'assigned',
                        'acceptance_status' => 'accepted',
                        'accepted_at' => $roundReviewer->accepted_at ?? now(),
                        'payment_status' => 'unpaid',
                        'deadline' => $round->close_date,
                        'currency' => 'KES',
                    ]);
                }

                foreach ($applicationIds as $appId) {
                    $existingAssignment = ReviewerApplicationAssignment::where('round_id', $round->id)
                        ->where('application_id', $appId)
                        ->where('reviewer_id', $reviewer->id)
                        ->first();

                    if ($existingAssignment) {
                        continue;
                    }

                    $application = ProgramApplication::find($appId);

                    if (in_array($application->round_status, ['scored', 'advanced', 'not_selected'], true)) {
                        throw new \RuntimeException("Application ID {$appId} has already been {$application->round_status} and cannot be reassigned.");
                    }

                    $assignment = ReviewerApplicationAssignment::create([
                        'round_id'       => $round->id,
                        'reviewer_id'    => $reviewer->id,
                        'reviewer_order_id' => $order->id,
                        'application_id' => $appId,
                        'status'         => 'assigned',
                        'assigned_at'    => now(),
                    ]);

                    $assignment->application->round_status = 'assigned';
                    $assignment->application->assigned_reviewer_id = $assignment->application->assigned_reviewer_id ?: $reviewer->id;
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
                'assignment'    => 'applications',
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

    // ─── PO takes an application back from its reviewer ──────────
    // DELETE /programs/rounds/{round}/applications/{application}/assignment
    public function unassignApplication(ProgramRound $round, ProgramApplication $application)
    {
        if (!\App\Service\Program\ProgramAccess::owns($round->program)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($round->status === 'finalized') {
            return response()->json(['message' => 'This round is finalized, so assignments can no longer be changed.'], 422);
        }

        if ((int) $application->current_round_id !== (int) $round->id) {
            return response()->json(['message' => 'This application is not in this round.'], 422);
        }

        $assignments = ReviewerApplicationAssignment::where('round_id', $round->id)
            ->where('application_id', $application->id)
            ->get();

        if ($assignments->isEmpty()) {
            return response()->json(['message' => 'This application is not assigned to a reviewer.'], 404);
        }

        // Once a reviewer has scored it, their work is kept
        $alreadyScored = \App\Models\Programs\Rounds\ApplicationScore::where('round_id', $round->id)
            ->where('application_id', $application->id)
            ->exists();

        if ($alreadyScored || $assignments->contains(fn ($a) => in_array($a->status, ['completed', 'scored'], true))) {
            return response()->json(['message' => 'A reviewer has already scored this application, so it can no longer be taken back.'], 422);
        }

        try {
            $reviewerIds = $assignments->pluck('reviewer_id')->unique();

            DB::transaction(function () use ($round, $application, $assignments) {
                $assignments->each->delete();

                // Back to waiting for an assignment (nobody else holds it any more)
                if (in_array($application->round_status, ['assigned', 'under_review'], true)) {
                    $application->round_status = 'submitted';
                }
                $application->assigned_reviewer_id = null;
                $application->save();

                // The reviewer's fee follows the applications they still hold
                ReviewerOrder::where('round_id', $round->id)->where('order_type', 'round_review')
                    ->get()->each->refreshRoundReviewFee();
            });

            $program = $round->program;
            foreach (User::whereIn('id', $reviewerIds)->get() as $reviewer) {
                try {
                    (new \App\Service\Notification\NotificationService())->create(
                        $reviewer->id,
                        auth()->id() ?? $reviewer->id,
                        "Application taken back\nAn application in {$round->round_name} of {$program->program_title} was taken off your list. Open Applications to see what is left.",
                        '',
                        'program'
                    );
                } catch (\Throwable $e) {
                    ErrorLogService::report($e, ['reviewer_id' => $reviewer->id]);
                }
            }

            return response()->json(['message' => 'The application is no longer assigned to a reviewer.'], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['round_id' => $round->id, 'application_id' => $application->id]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // ─── Reviewer starts reviewing an application ─────────────────
    // POST /programs/rounds/{round}/applications/{application}/start-review
    public function startReview(ProgramRound $round, ProgramApplication $application)
    {
        $user = Auth::user();

        $userId = $user->id;

        if (!$user->lipr_wallet) {
            throw new \RuntimeException('You must onboard to Mpesa Lipr before starting a review.', 422);
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

        $isInternalReviewer = $roundReviewer->reviewer_type === 'internal';

        if (!$isInternalReviewer && !$user->stripe_connect_id && !$user->lipr_wallet) {
            return response()->json(['error' => 'You must onboard to Stripe or Mpesa before starting a review.'], 422);
        }

        if ($assignment->status === 'completed') {
            return response()->json(['error' => 'Already reviewed'], 422);
        }

        // ─── WALLET FUNDS CHECK ───────────────────────────────────
        $walletCheck = $isInternalReviewer
            ? ['sufficient' => true, 'required' => 0, 'available' => 0, 'shortfall' => 0]
            : $this->checkWalletFundsForReviewers($round);
        if (!$walletCheck['sufficient']) {
            $this->programNotification->send('reviewer.accepted_insufficient_funds', [
                $round->program->owner,
            ], [
                'program_id'    => $round->program_id,
                'program_title' => $round->program->program_title,
                'round_name'    => $round->round_name,
                'required'      => $walletCheck['required'],
                'available'     => $walletCheck['available'],
                'shortfall'     => $walletCheck['shortfall'],
                'currency'      => $round->program->currency ?: config('app.currency', 'KES'),
            ]);

            return response()->json([
                'error'     => 'Insufficient program wallet funds to cover reviewer fees, program owner notified .',
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
    public function myAssignedApplications(ProgramRound $round)
    {
        $userId = auth()->id();

        $assignments = ReviewerApplicationAssignment::where('reviewer_id', $userId)
            ->where('round_id', $round->id)
            ->with([
                'round.program',
                'application.program',
                'application.currentRound',
                // only this round's answers, documents and the reviewer's own score for this round
                'application.roundAnswers' => fn ($query) => $query->where('round_id', $round->id),
                'application.roundDocuments' => fn ($query) => $query->where('round_id', $round->id),
                'application.scores' => function ($query) use ($userId, $round) {
                    $query->where('reviewer_id', $userId)->where('round_id', $round->id);
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
            ->whereIn('round_status', ['draft', 'submitted', 'under_review'])
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
            $app->assigned_reviewer_id = $newReviewerId;
            $app->save();
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
                $app->assigned_reviewer_id = $reviewer->user_id;
                $app->save();
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
            ->sum('fee_amount');

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
