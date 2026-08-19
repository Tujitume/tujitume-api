<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\MilestonePreAgreement;
use App\Models\Programs\MilestoneSupplier;
use App\Models\Programs\MilestoneVerification;
use App\Models\Programs\SupplierDirectory;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgramMilestoneController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($applicationId)
    {
        try {
            $milestones = ProgramMilestone::with(['budgetItems', 'application', 'suppliers', 'disbursements'])
                ->where('app_id', $applicationId)->orderBy('sequence_order')->get();
            return response()->json(['milestones' => $milestones]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, ProgramApplication $application)
    {
        // Authorization: only the applicant can create milestones
        if ( $application->user_id !== Auth::id() ) {
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        $uploadedFiles = [];

        DB::beginTransaction();

        try{
            $validated = $request->validate([
                // Required fields
                'title' => 'required|string|max:500',
                'amount' => 'required|numeric|min:0',
                'purpose_type' => 'required|in:capex,opex,services,mixed',
                'expected_outcomes' => 'required|string',

                // Optional fields
                'description' => 'nullable|string|max:1000',
                'document' =>    'nullable|file|mimes:pdf,doc,docx,xls,xlsx|max:2048', // max 5MB
                'sequence_order' => 'nullable|integer|min:1',
                'duration_days ' => 'nullable|integer|min:1',
            ]);

            // Auto-set fields
            $validated['app_id'] = $application->id;
            $validated['status'] = 'pending';
            $validated['fund_release_status'] = 'locked';
            $validated['estimated_completion_date'] = now()->addDays($validated['duration_days'] ?? 14);

            // Auto-calculate sequence_order if not provided
            if (!isset($validated['sequence_order'])) {
                $lastMilestone = ProgramMilestone::where('app_id', $application->id)
                    ->orderBy('sequence_order', 'desc')
                    ->first();
                $validated['sequence_order'] = $lastMilestone ? $lastMilestone->sequence_order + 1 : 1;
            }

            // Validate total doesn't exceed awarded amount
            $totalMilestones = ProgramMilestone::where('app_id', $application->id)->sum('amount');
            $newTotal = $totalMilestones + $validated['amount'];

            if ($newTotal > $application->total_amount_requested) {
                return response()->json([
                    'error' => 'Total milestone amounts cannot exceed awarded amount of ' . $application->total_amount_requested
                ], 422);
            }

            $milestone = ProgramMilestone::create($validated);

            DB::commit();

            return response()->json([
                'message' => 'Milestone created successfully',
                'data' => $milestone,
            ], 200);
        }
        catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors'  => $e->errors()], 422);
        }
        catch (\Exception $e) {
            DB::rollBack();

            foreach ($uploadedFiles as $file) {
                if ($file && file_exists($file)) {
                    unlink($file);
                }
            }
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
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

    public function award(Request $request, ProgramApplication $application)
    {
        $user_id = Auth::id();
        if ($application->program_owner_id !== $user_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check application is approved
        if ($application->status !== 'approved') {
            return response()->json([
                'error' => 'Only approved applications can be awarded. Current status: ' . $application->status
            ], 422);
        }

        try{
            $milestonesCount = $application->program_milestones()->count();
            if ($milestonesCount === 0) {
                return response()->json(['error' => 'Cannot award program. Business owner must define milestones first.'], 422);
            }

            $totalMilestones = $application->program_milestones()->sum('amount');
            $awardedAmount = $totalMilestones;

            // Check program wallet has sufficient funds
            $program = $application->program;
            $wallet = $program->wallet;

            if ($wallet && $wallet->status === 'active' && $wallet->balance >= $awardedAmount) {
                // Reserve funds in wallet
                $wallet->total_reserved += $awardedAmount;
                $wallet->balance -= $awardedAmount;
                $wallet->save();
            }

            // Update application
            $application->awarded_amount = $awardedAmount;
            $application->status = 'awarded';
            $application->save();

            return response()->json([
                'message' => 'Program awarded successfully. Business owner can now begin Milestone 1.',
                'data' => $application,
            ]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    # Creates/Update milestone with is_template=true, created_by_role='program_owner'
    public function storeTemplate(Request $request, $applicationId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $application = ProgramApplication::findOrFail($applicationId);

            // Authorization: Only program owner can create templates
            if ($application->program->user_id !== $userId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Validate application is in correct state
            if ($application->status !== 'awarded') {
                return response()->json(['error' => 'Application must be awarded'], 400);
            }

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'amount' => 'required|numeric|min:0',
                'sequence_order' => 'nullable|integer|min:1',
                'target_completion_date' => 'nullable|date',
                'mprv_required' => 'nullable|boolean',
                'mid_milestone_required' => 'nullable|boolean',
                'final_approval_required' => 'nullable|boolean',
                'mid_milestone_notes' => 'nullable|string',
                'mprv_notes' => 'nullable|string',
                'final_approval_notes' => 'nullable|string',
                //'planning_mode' => 'nullable|in:locked,hybrid',
                'allowed_edits' => 'nullable|array',

                // Supplier assignment (optional)
                'supplier_id' => 'nullable|exists:supplier_directories,id',
                'assignment_type' => 'nullable|in:primary,approved,preferred',
                'payment_route' => 'nullable|in:direct_to_supplier,split,direct_to_applicant',
                'is_locked' => 'nullable|boolean',
            ]);

            // Auto-calculate sequence_order if not provided
            if (!isset($validated['sequence_order'])) {
                $lastMilestone = ProgramMilestone::where('app_id', $application->id)
                    ->orderBy('sequence_order', 'desc')
                    ->first();
                $validated['sequence_order'] = $lastMilestone ? $lastMilestone->sequence_order + 1 : 1;
            }

            $restrictedForApplicant = [
                'amount',
                'planning_mode',
                'allowed_edits',
            ];

            $restrictedMatched = array_intersect(
                $validated['allowed_edits'] ?? [], $restrictedForApplicant
            );

            if (!empty($restrictedMatched)) {

                return response()->json([
                    'error' => 'The following fields cannot be editable by applicant: ' . implode(', ', $restrictedMatched)
                ], 422);
            }



            // Create template milestone
            $milestone = ProgramMilestone::create([
                'app_id' => $applicationId,
                'title' => $validated['title'],
                'description' => $validated['description'],
                'amount' => $validated['amount'],
                'sequence_order' => $validated['sequence_order'],
                'estimated_completion_date' => $validated['target_completion_date'] ?? null,
                'status' => 'pending',
                'fund_release_status' => 'locked',
                'is_template' => true,
                'created_by_role' => 'program_owner',
                'allowed_edits' => $validated['allowed_edits'] ?? null,

                'mprv_required' => $validated['mprv_required'] ?? false,
                'mid_milestone_required' => $validated['mid_milestone_required'] ?? false,
                'final_approval_required' => $validated['mid_milestone_required'] ?? false,

                'mprv_notes' => $validated['mprv_notes'] ?? null,
                'mid_milestone_notes' => $validated['mid_milestone_notes'] ?? null,
                'final_approval_notes' => $validated['mid_milestone_notes'] ?? null,
            ]);


            // Assign supplier if provided
            if (isset($validated['supplier_id'])) {
                // Verify supplier belongs to program owner
                $supplier = SupplierDirectory::where('id', $validated['supplier_id'])
                    ->where('user_id', $userId)
                    ->firstOrFail();

                MilestoneSupplier::create([
                    'milestone_id' => $milestone->id,
                    'supplier_id' => $supplier->id,
                    'assignment_type' => $validated['assignment_type'] ?? 'approved',
                    'payment_route' => $validated['payment_route'] ?? 'direct_to_supplier',
                    'is_locked' => $validated['is_locked'] ?? false,
                ]);
            }

            // Update application funding setup status
            if ($application->funding_setup_status === 'not_started') {
                $application->update(['funding_setup_status' => 'in_progress']);
            }

            DB::commit();

            return response()->json([
                'message' => 'Template milestone created successfully',
                'data' => $milestone->load('suppliers.supplierDirectory'),
            ], 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Resource not found'], 404);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * List template milestones
     * GET /program/applications/{application_id}/milestones/templates
     */
    public function listTemplates($applicationId)
    {
        $userId = auth()->id();

        try {
            $application = ProgramApplication::findOrFail($applicationId);

            // Authorization: Program owner or applicant can view
            if ($application->program->user_id !== $userId && $application->user_id !== $userId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $templates = ProgramMilestone::where('app_id', $applicationId)
                //->where('is_template', true)
                ->with([
                    'suppliers',
                    'budgetItems'
                ])
                ->orderBy('sequence_order')
                ->get();

            $totalAmount = $templates->sum('amount');
            $approvedAmount = $application->total_amount_requested;

            return response()->json([
                'data' => $templates,
                'summary' => [
                    'total_milestones' => $templates->count(),
                    'total_allocated' => $totalAmount,
                    'approved_amount' => $approvedAmount,
                    'remaining' => $approvedAmount - $totalAmount,
                    'is_complete' => abs($approvedAmount - $totalAmount) < 0.01, // Account for floating point
                    'funding_setup_status' => $application->funding_setup_status,
                ],
            ], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Update template milestone
     * PATCH /program/milestones/templates/{milestone_id}
     */
    public function updateTemplate(Request $request, $milestoneId)
    {

        try{
            $userId = auth()->id();
            $milestone = ProgramMilestone::where('is_template', true)->findOrFail($milestoneId);

            $application = $milestone->application;
            $isProgramOwner = $application->program->user_id === $userId;
            $isApplicant = $application->user_id === $userId;

            $requestData = $request->all();

            $baseRules = [
                'title' => 'sometimes|required|string|max:255',
                'description' => 'sometimes|string',
                'estimated_completion_date' => 'nullable|date',
                'expected_outcomes' => 'sometimes|string',
                'document' => 'sometimes|file|mimes:pdf,doc,docx,ppt,pptx',
                'can_add_suppliers' => 'sometimes|numeric|min:0',
                'can_add_budget_items' => 'sometimes|array',
                //'notes' => 'nullable|string',
            ];

            // Program owner can edit EVERYTHING
            if ($isProgramOwner) {
                // Allow all fields
                $rules = $baseRules;
            }
            // Applicant can ONLY edit allowed fields (hybrid mode)
            elseif ($isApplicant && $application->planning_mode === 'hybrid') {
                // Check if funding setup is still in progress (not submitted yet)
                if ($application->funding_setup_status === 'awaiting_review') {
                    return response()->json(['error' => 'Plan already submitted. Cannot edit.'], 403);
                }

                $allowedEdits = $milestone->allowed_edits ?? [];

                if (empty($allowedEdits)) {
                    return response()->json([
                        'error' => 'No editable fields available for this milestone.'
                    ], 403);
                }

                // Only keep allowed fields from baseRules
                $rules = array_intersect_key($baseRules, array_flip($allowedEdits));

                // Check if request contains forbidden fields
                $unauthorizedFields = array_diff(array_keys($request->all()), array_keys($rules));

                if (!empty($unauthorizedFields)) {
                    return response()->json([
                        'error' => 'You are not allowed to edit: ' . implode(', ', $unauthorizedFields)
                    ], 403);
                }

            } else {
                return response()->json(['error' => 'Unauthorized', 'user_id' => $userId,
                    'mode' => $application->planning_mode ], 403);
            }

            // Validate all rules
            //$validated = $request->validate($rules);
            $validated = validator($requestData, $rules)->validate();

            // Handle document upload
            if ($request->hasFile('document')) {
                $doc_path          = "files/program/milestones/{$milestoneId}";
                $validated['document'] = $this->fileUpload->saveFile($request->file('document'), $doc_path);
            } else {
                $validated['document'] = $milestone->document; // keep existing
            }

            if($request->has('mprv_required')){
                $validated['mprv_required']  = $request->mprv_required;

                if($request->has('mprv_notes')){
                    $validated['mprv_notes']    = $request->mprv_notes;
                }
            }

            if($request->has('mid_milestone_required')){
                $validated['mid_milestone_required']  = $request->mid_milestone_required;

                if($request->has('mid_milestone_notes')){
                    $validated['mid_milestone_notes']    = $request->mprv_notes;
                }
            }

            if($request->has('final_approval_required')){
                $validated['final_approval_required']  = $request->final_approval_required;

                if($request->has('mprv_notes')){
                    $validated['final_approval_notes']    = $request->final_approval_notes;
                }
            }



            // Remove action permissions - not DB columns
            unset($validated['can_add_suppliers']);
            unset($validated['can_add_budget_items']);


            $milestone->update($validated);

            return response()->json([
                'message' => 'Milestone updated successfully',
                'data' => $milestone->fresh()
            ], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }

    }
    /**
     * Activate template milestones (complete funding setup)
     * POST /program/applications/{application_id}/milestones/activate
     */
    public function activateTemplates($applicationId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $application = ProgramApplication::findOrFail($applicationId);

            // Authorization: Only program owner can activate locked mode
            if ( $application->planning_mode == 'locked' && $application->program->user_id !== $userId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Get all template milestones
            $templates = ProgramMilestone::where('app_id', $applicationId)
                //->where('is_template', true)
                ->get();

            if ($templates->isEmpty()) {
                return response()->json(['error' => 'No template milestones found'], 400);
            }

            // Validate total allocation equals approved amount
            $totalAmount = $templates->sum('amount');
            $approvedAmount = $application->total_amount_requested;

            if (abs($totalAmount - $approvedAmount) > 0.01) {
                throw ValidationException::withMessages([
                    'amount' => ["Total milestone allocation ($totalAmount) must equal approved amount ($approvedAmount)"]
                ]);
            }



            // Activate all templates
            foreach ($templates as $template) {

                if($application->planning_mode == 'hybrid') {
                    // pre-agreements check
                    $required = array_filter([
                        $template->mprv_required         ? 'mprv'           : null,
                        $template->mid_milestone_required ? 'mid_milestone'  : null,
                        $template->final_approval_required? 'final_approval' : null,
                    ]);

                    if (!empty($required)) {
                        $agreedCount = MilestonePreAgreement::where('milestone_id', $template->id)
                            ->whereIn('verification_type', $required)
                            ->where('status', 'agreed')->count();

                        if ($agreedCount !== count($required)) {
                            return response()->json([
                                'message' => "Milestone '{$template->title}' has pending pre-agreements.",
                            ], 422);
                        }
                    }
                }

                $template->update(['is_template' => false]);
            }

            // Update application status
            $application->update([
                'funding_setup_status' => 'completed'
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Funding setup completed successfully. Milestones activated.',
                'data' => [
                    'activated_milestones' => $templates->count(),
                    'total_amount' => $totalAmount,
                ],
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Application not found'], 404);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }


    /**
     * Delete template milestone
     * DELETE /program/milestones/templates/{milestone_id}
     */
    public function destroyTemplate($milestoneId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $milestone = ProgramMilestone::findOrFail($milestoneId); //where('is_template', true)

            // Authorization: Only program owner
            if ($milestone->application->program->user_id !== $userId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Delete associated budget & suppliers
            $milestone->budgetItems()->delete();

            $milestone->suppliers()->delete();

            // Delete milestone
            $milestone->delete();

            DB::commit();

            return response()->json([
                'message' => 'Template milestone deleted successfully'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Template milestone not found'], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Applicant or owner submits customized plan for review (hybrid mode only)
     * POST /program/applications/{application_id}/milestones/submit-plan
     */
    public function submitPlan($applicationId)
    {
        $userId = auth()->id();


        $application = ProgramApplication::find($applicationId);
        $isProgramOwner = $application->program->user_id === $userId;
        $isApplicant = $application->user_id === $userId;

        DB::beginTransaction();
        try {
            $application = ProgramApplication::with([
                'program','program_milestones.suppliers','program_milestones.budgetItems',
            ])->findOrFail($applicationId);

            // Authorization:
            if($isProgramOwner) {
                $status = 'awaiting_applicant_revision';
            }
            elseif ($isApplicant && $application->planning_mode === 'hybrid') {

                if ($application->program_milestones->isEmpty()) {
                    return response()->json(['message' => 'No milestones found.'], 422);
                }

                if($application->program->disbursement_type  == 'supplier'){
                    foreach($application->program_milestones as $milestone){
                        if( $milestone->suppliers->count() === 0 ) {
                            return response()->json(['message' => 'All milestones must have at least 1 supplier.'], 422);
                        }

                        if( $milestone->budgetItems->count() === 0 ) {
                            return response()->json(['message' => 'All milestones must have at least 1 budget item.'], 422);
                        }
                    }
                }

                $status = 'awaiting_owner_review';
            }
            else{
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Verify funding setup is in progress
            if ($application->funding_setup_status === 'not_started') {
                return response()->json(['error' => 'Funding setup has not started.'], 400);
            }

            // Update status to awaiting review
            $application->update([
                'funding_setup_status' => $status
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Plan submitted for review successfully'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }


    /* Used by Program Owner to reject applicant's submitted plan and request revisions
     * OR by Applicant to request changes from Program Owner's locked plan
    */
    public function requestChanges(Request $request, $applicationId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $application = ProgramApplication::findOrFail($applicationId);

            // Authorization: Program owner OR applicant
            $isProgramOwner = $application->program->user_id === $userId;
            $isApplicant = $application->user_id === $userId;

            if (!$isProgramOwner && !$isApplicant) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Can only request changes if plan is awaiting review
            if ($application->funding_setup_status !== 'awaiting_owner_review') {
                return response()->json([
                    'error' => 'Can only request changes when plan is awaiting review. Current status: ' . $application->funding_setup_status
                ], 400);
            }

            $validated = $request->validate([
                'revision_notes' => 'required|string|max:2000',
                'checklist' => 'nullable|array',
                'checklist.*.item' => 'required|string|max:500',
                'checklist.*.status' => 'required|in:pending,needs_revision,approved',
                'checklist.*.notes' => 'nullable|string|max:500',
            ]);

            // Update application status back to in_progress
            $application->update([
                'funding_setup_status' => 'awaiting_applicant_revision',
                'revision_requested_by' => $isProgramOwner ? 'program_owner' : 'applicant',
                'revision_notes' => $validated['revision_notes'],
                'revision_checklist' => $validated['checklist'] ?? null,
                'revision_requested_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Changes requested successfully. Plan sent back for revision.',
                'data' => [
                    'funding_setup_status' => 'awaiting_applicant_revision',
                    'requested_by' => $isProgramOwner ? 'program_owner' : 'applicant',
                    'revision_notes' => $validated['revision_notes'],
                    'checklist_items' => isset($validated['checklist']) ? count($validated['checklist']) : 0,
                ]
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Get revision feedback
     * GET /program/funding-setup/{application_id}/revision-feedback
     */
    public function getRevisionFeedback($applicationId)
    {
        $userId = auth()->id();

        try {
            $application = ProgramApplication::findOrFail($applicationId);

            // Authorization: Program owner OR applicant
            $isProgramOwner = $application->program->user_id === $userId;
            $isApplicant = $application->user_id === $userId;

            if (!$isProgramOwner && !$isApplicant) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            if (!$application->revision_requested_by) {
                return response()->json([
                    'message' => 'No revision feedback available',
                    'data' => null
                ], 200);
            }

            return response()->json([
                'data' => [
                    'revision_requested_by' => $application->revision_requested_by,
                    'revision_notes' => $application->revision_notes,
                    'revision_checklist' => $application->revision_checklist,
                    'revision_requested_at' => $application->revision_requested_at,
                    'funding_setup_status' => $application->funding_setup_status,
                ]
            ], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

}
