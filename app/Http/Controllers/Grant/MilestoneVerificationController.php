<?php

namespace App\Http\Controllers\Grant;

use App\Http\Controllers\Controller;
use App\Models\Grants\GrantMilestone;
use App\Models\Grants\MilestoneVerification;
use App\Models\Milestones\MilestoneCompletionSubmission;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MilestoneVerificationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GrantMilestone $milestone)
    {
        try {
            $verifications = MilestoneVerification::where('milestone_id', $milestone->id)
                //->with(['submitter', 'decider', 'auditor'])
                ->orderBy('created_at', 'desc')
                ->get();
            return response()->json(['verifications' => $verifications]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, GrantMilestone $milestone)
    {
        // Authorization: only the applicant can submit MPRV
        if ($milestone->application->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check milestone status
        if (!in_array($milestone->status, ['pending', 'submitted', 'rejected'])) {
            return response()->json([
                'error' => 'MPRV can only be submitted for pending or rejected milestones. Current status: ' . $milestone->status
            ], 422);
        }

        $suppliersCount = $milestone->suppliers()->count();
        if ($suppliersCount === 0) {
            return response()->json(['error' => 'Cannot submit MPRV. Please add suppliers first.'], 422);
        }

        $budgetItemsCount = $milestone->budgetItems()->count();
        if ($budgetItemsCount === 0) {
            return response()->json(['error' => 'Cannot submit MPRV. Please add budget items first.'], 422);
        }

        $budgetTotal = $milestone->budgetItems()->sum('total_cost');
        if ($budgetTotal != $milestone->amount) {
            return response()->json(['error' => 'Budget items total (' . $budgetTotal . ') must match milestone amount (' . $milestone->amount . ')'], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                // Required declarations
                'conflict_of_interest_confirmed' => 'required|boolean|accepted',
                'funds_usage_confirmed' => 'required|boolean|accepted',
                // Optional
                'additional_declarations' => 'nullable|string',
                'verification_type' => 'required|string|in:mprv, mid_milestone',
                'document' => 'nullable|file|mimes:pdf,doc,docx|max:5120',  // 5MB
            ]);

            // Get attempt number (for resubmissions)
            $lastAttempt = MilestoneVerification::where('milestone_id', $milestone->id)
                ->max('attempt_number');
            $validated['attempt_number'] = $lastAttempt ? $lastAttempt + 1 : 1;

            $validated['milestone_id'] = $milestone->id;
            $validated['submitted_by'] = auth()->id();
            $validated['decision'] = 'pending';

            // Handle file uploads
            $uploadedFiles = [];
            if ($request->hasFile('document')) {
                $path = 'files/grantMilestoneVerifications/' . $milestone->id;
                $filePath = $this->fileUpload->saveFile($request->file('document'), $path);
                $validated['document'] = $filePath;

                $uploadedFiles[] = $filePath;
            }

            if($validated['document'] == null){
                return response()->json(['error' => 'File upload failed in backend.'. $request->file('document')], 422);
            }
            $verification = MilestoneVerification::create($validated);

            // Update milestone status
            $milestone->status = 'submitted';
            $milestone->mprv_ready = false;
            $milestone->save();

            DB::commit();

            $this->grantNotification->send('mprv.submitted', [$milestone->application->grant->owner], [
                'business_name' => $milestone->application->startup_name, 'milestone_number' => $milestone->sequence_order,
                'application_id' => $milestone->app_id,
            ]);

            return response()->json([
                'message' => 'MPRV submitted successfully. Grant owner will review.',
                'file' => $request->file('document'),
                's3' => $validated['document'] ?? 'n/a',
                'data' => $verification,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            DB::rollBack();

//            foreach ($uploadedFiles[] as $file) {
//
//            }

            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function approve(Request $request, MilestoneVerification $verification)
    {
        // Authorization: only grant owner can approve
        $milestone = $verification->milestone; $owner_id = Auth::id();
        if ($milestone->application->grant_owner_id !== $owner_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check if already decided
        if ($verification->decision !== 'pending') {
            return response()->json([
                'error' => 'This verification has already been ' . $verification->decision
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'decision_notes' => 'nullable|string',
            ]);

            // Update verification
            $verification->decision = 'approved';
            $verification->decided_by =$owner_id;
            $verification->decided_at = now();
            $verification->decision_notes = $validated['decision_notes'] ?? null;
            $verification->save();

            // Update milestone
            $milestone->status = 'approved';
            $milestone->fund_release_status = 'approved';
            $milestone->approved_by = $owner_id;
            $milestone->approved_at = now();
            $milestone->save();

            DB::commit();

            $this->grantNotification->send('mprv.approved', [$verification->milestone->application->user], [
                'milestone_number' => $verification->milestone->sequence_order, 'application_id' => $verification->milestone->app_id,
            ]);

            return response()->json([
                'message' => 'MPRV approved. Milestone ready for disbursement.',
                'data' => $verification,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Reject MPRV
     * PATCH /api/v1/verifications/{verification}/reject
     */
    public function reject(Request $request, MilestoneVerification $verification)
    {
        // Authorization: only grant owner can reject
        $milestone = $verification->milestone;
        if ($milestone->application->grant_owner_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check if already decided
        if ($verification->decision !== 'pending') {
            return response()->json([
                'error' => 'This verification has already been ' . $verification->decision
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'decision_notes' => 'required|string',
            ]);

            // Update verification
            $verification->decision = 'rejected';
            $verification->decided_by = auth()->id();
            $verification->decided_at = now();
            $verification->decision_notes = $validated['decision_notes'];
            $verification->save();

            // Update milestone
            $milestone->status = 'rejected';
            $milestone->rejection_reason = $validated['decision_notes'];
            $milestone->save();

            DB::commit();

            $this->grantNotification->send('mprv.rejected', [$verification->milestone->application->user], [
                'milestone_number' => $verification->milestone->sequence_order, 'reason' => $validated['decision_notes'],
                'application_id' => $verification->milestone->app_id,
            ]);

            return response()->json([
                'message' => 'MPRV rejected. Business owner can fix issues and resubmit.',
                'data' => $verification,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Request Audit
     * PATCH /api/v1/verifications/{verification}/request-audit
     */
    public function requestAudit(Request $request, MilestoneVerification $verification)
    {
        // Authorization: only grant owner can request audit
        $milestone = $verification->milestone;
        if ($milestone->application->grant_owner_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check if already decided
        if ($verification->decision !== 'pending') {
            return response()->json([
                'error' => 'This verification has already been ' . $verification->decision
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'auditor_id' => 'required|exists:users,id',
                'decision_notes' => 'nullable|string',
            ]);

            // Update verification
            $verification->decision = 'audit_requested';
            $verification->decided_by = auth()->id();
            $verification->decided_at = now();
            $verification->decision_notes = $validated['decision_notes'] ?? null;
            $verification->auditor_id = $validated['auditor_id'];
            $verification->audit_started_at = now();
            $verification->save();

            // Update milestone
            $milestone->status = 'audit_requested';
            $milestone->save();

            DB::commit();

            $this->grantNotification->send('mprv.audit_requested', [User::find($validated['auditor_id'])], [
                'business_name' => $verification->milestone->application->startup_name, 'milestone_number' => $verification->milestone->sequence_order,
                'application_id' => $verification->milestone->app_id,
            ]);

            return response()->json([
                'message' => 'Audit requested. Project Manager has been assigned.',
                'data' => $verification,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    # M i l e s t o n e   C o m p l e t i o n s

    public function index_completions(GrantMilestone $milestone)
    {
        $userId = auth()->id();
        $grantOwnerId = $milestone->application->grant_owner_id;
        $businessOwnerId = $milestone->application->user_id;

        if (!in_array($userId, [$grantOwnerId, $businessOwnerId])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get all completion submissions for this milestone
        $completions = MilestoneCompletionSubmission::where('milestone_id', $milestone->id)
            ->orderBy('attempt_number', 'desc')->get();

        return response()->json([
            'data' => $completions,
        ]);
    }

    public function store_completion(Request $request, GrantMilestone $milestone)
    {
        $userId = auth()->id();

        // Authorization: only the applicant can submit completion
        if ($milestone->application->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check milestone status
        if (!in_array($milestone->status, ['approved', 'disbursing', 'completed', 'completion_rejected'])) {
            return response()->json([
                'error' => 'Completion can only be submitted for approved or disbursed milestones. Current status: ' . $milestone->status
            ], 422);
        }

        // Check if funds have been released
        if ($milestone->fund_release_status !== 'released') {
            return response()->json([
                'error' => 'Funds must be fully released before submitting completion. Current status: ' . $milestone->fund_release_status
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                // Required fields
                'completion_report' => 'required|string',

                // Optional fields
                'delivery_notes' => 'nullable|string',
                'proof_files' => 'nullable|array',
                'proof_files.*' => 'file|max:5120',  // 5MB per file
            ]);

            // Get attempt number (for resubmissions)
            $lastAttempt = MilestoneCompletionSubmission::where('milestone_id', $milestone->id)
                ->max('attempt_number');
            $validated['attempt_number'] = $lastAttempt ? $lastAttempt + 1 : 1;

            $validated['milestone_id'] = $milestone->id;
            $validated['submitted_by'] = $userId;
            $validated['decision'] = 'pending';

            // Handle file uploads
            $uploadedFiles = [];
            if ($request->hasFile('proof_files')) {
                $path = 'files/grantCompletionProofs/' . $milestone->id;

                foreach ($request->file('proof_files') as $file) {
                    $filePath = $this->fileUpload->saveFile($file, $path);
                    $uploadedFiles[] = $filePath;
                }

                $validated['proof_files'] = json_encode($uploadedFiles);
            }

            $completion = MilestoneCompletionSubmission::create($validated);

            // Update milestone status
            $milestone->status = 'completed';
            $milestone->completed_at = now();
            $milestone->save();

            DB::commit();

            $this->grantNotification->send('completion.submitted', [$milestone->application->grant->owner], [
                'business_name' => $milestone->application->startup_name, 'milestone_number' => $milestone->sequence_order,
                'application_id' => $milestone->app_id,
            ]);

            return response()->json([
                'message' => 'Completion submitted successfully. Grant owner will review.',
                'data' => $completion,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Approve completion
     * PATCH /api/v1/completions/{completion}/approve
     */
    public function approve_completion(Request $request, MilestoneCompletionSubmission $completion)
    {
        $userId = auth()->id();

        // Authorization: only grant owner can approve
        $milestone = $completion->milestone;
        if ($milestone->application->grant_owner_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check if already decided
        if ($completion->decision !== 'pending') {
            return response()->json([
                'error' => 'This completion has already been ' . $completion->decision
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'decision_notes' => 'nullable|string',
            ]);

            // Update completion
            $completion->decision = 'approved';
            $completion->decided_by = $userId;
            $completion->decided_at = now();
            $completion->decision_notes = $validated['decision_notes'] ?? null;
            $completion->save();

            // Update milestone
            $milestone->status = 'completion_approved';
            $milestone->completion_approved_by = $userId;
            $milestone->completion_approved_at = now();
            $milestone->save();

            // Unlock next milestone (if exists)
            $nextMilestone = GrantMilestone::where('app_id', $milestone->app_id)
                ->where('sequence_order', $milestone->sequence_order + 1)
                ->first();

            if ($nextMilestone && $nextMilestone->status === 'pending') {
                // Next milestone can now begin
                $nextMilestone->notes = 'Unlocked: Previous milestone completed';
                $nextMilestone->save();
            }

            DB::commit();

            $this->grantNotification->send('completion.approved', [$completion->milestone->application->user], [
                'milestone_number' => $completion->milestone->sequence_order, 'next_milestone' => $nextMilestone ? true : false,
                'application_id' => $completion->milestone->app_id,
            ]);

            // If next milestone exists
            if ($nextMilestone) {
                $this->grantNotification->send('milestone.unlocked', [$completion->milestone->application->user], [
                    'milestone_number' => $nextMilestone->sequence_order, 'application_id' => $completion->milestone->app_id,
                ]);
            }

            return response()->json([
                'message' => 'Completion approved. Milestone complete! ' .
                    ($nextMilestone ? 'Next milestone unlocked.' : 'All milestones complete!'),
                'data' => $completion,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Reject completion
     * PATCH /api/v1/completions/{completion}/reject
     */
    public function reject_completion(Request $request, MilestoneCompletionSubmission $completion)
    {
        $userId = auth()->id();

        // Authorization: only grant owner can reject
        $milestone = $completion->milestone;
        if ($milestone->application->grant_owner_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check if already decided
        if ($completion->decision !== 'pending') {
            return response()->json([
                'error' => 'This completion has already been ' . $completion->decision
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'decision_notes' => 'required|string',
            ]);

            // Update completion
            $completion->decision = 'rejected';
            $completion->decided_by = $userId;
            $completion->decided_at = now();
            $completion->decision_notes = $validated['decision_notes'];
            $completion->save();

            // Update milestone
            $milestone->status = 'completion_rejected';
            $milestone->save();

            DB::commit();

            $this->grantNotification->send('completion.rejected', [$completion->milestone->application->user], [
                'milestone_number' => $completion->milestone->sequence_order, 'reason' => $validated['decision_notes'],
                'application_id' => $completion->milestone->app_id,
            ]);

            return response()->json([
                'message' => 'Completion rejected. Business owner can fix issues and resubmit.',
                'data' => $completion,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

}
