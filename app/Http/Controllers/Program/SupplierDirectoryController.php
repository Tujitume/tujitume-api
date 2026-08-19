<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\MilestoneSupplier;
use App\Models\Programs\SupplierDirectory;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierDirectoryController extends Controller
{
    /**
     * List all suppliers for authenticated user
     * GET /program/supplier-directory
     */
    public function index(Request $request)
    {
        $userId = auth()->id();

        try {
            $query = SupplierDirectory::where('user_id', $userId);

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Filter by supplier type
            if ($request->has('supplier_type')) {
                $query->where('supplier_type', $request->supplier_type);
            }

            // Search by name, email, or phone
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('legal_name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%');
                });
            }

            // Order by name
            $suppliers = $query->orderBy('legal_name')->paginate(20);

            return response()->json($suppliers, 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Get single supplier details
     * GET /program/supplier-directory/{supplier_id}
     */
    public function show($supplierId)
    {
        $userId = auth()->id();

        try {
            $supplier = SupplierDirectory::where('user_id', $userId)
                ->findOrFail($supplierId);

            return response()->json(['data' => $supplier], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Supplier not found'], 404);

        } catch (\Exception $e) {
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * List milestones assigned to a supplier owned by the authenticated program user.
     * GET /program/supplier-directory/{supplierId}/assigned-milestones
     */
    public function assignedMilestones(Request $request, $supplierId)
    {
        try {
            $supplier = SupplierDirectory::where('user_id', auth()->id())
                ->findOrFail($supplierId);

            $assignments = $supplier->milestoneAssignments()
                ->with([
                    'milestone.budgetItems',
                    'milestone.application.program',
                    'milestone.application.business',
                ])
                ->latest()
                ->paginate($request->integer('per_page', 20));

            return response()->json($assignments, 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Supplier not found'], 404);
        } catch (\Exception $e) {
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Create new supplier
     * POST /program/supplier-directory
     */
    public function store(Request $request)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $validated = $request->validate([

                // Basic Identity
                'legal_name' => 'required|string|max:255',
                'contact_person' => 'nullable|string|max:150',
                'phone' => 'required|string|max:30',
                'email' => 'required|email|max:191',
                'supplier_type' => 'nullable|string|max:100',
                // Payment Method
                'payment_method' => 'required|in:mpesa_mobile,mpesa_lipr,mpesa_paybill,mpesa_till,bank_transfer,other',

                // LIPR
                'lipr_wallet' => [
                    'nullable',
                    'string',
                    'max:30',
                ],

                'lipr_mobile_number' => [
                    'nullable',
                    'string',
                    'max:30',
                ],

                // Paybill
                'mpesa_paybill_number' => [
                    'nullable',
                    'string',
                    'max:20',
                    'required_if:payment_method,mpesa_paybill',
                ],

                'mpesa_paybill_account' => [
                    'nullable',
                    'string',
                    'max:20',
                    'required_if:payment_method,mpesa_paybill',
                ],

                // Till
                'mpesa_till_number' => [
                    'nullable',
                    'string',
                    'max:20',
                    'required_if:payment_method,mpesa_till',
                ],

                'mpesa_account_reference' => [
                    'nullable',
                    'string',
                    'max:100',
                    'required_if:payment_method,mpesa_till',
                ],

                // Bank
                'bank_name' => [
                    'nullable',
                    'string',
                    'max:100',
                    'required_if:payment_method,bank_transfer',
                ],

                'bank_account_number' => [
                    'nullable',
                    'string',
                    'max:50',
                    'required_if:payment_method,bank_transfer',
                ],

                'bank_branch' => 'nullable|string|max:100',
                'bank_swift_code' => 'nullable|string|max:20',

                // Other
                'notes' => 'nullable|string',
                'is_active' => 'nullable|boolean',

                'city' => 'nullable|string',
                'country' => 'nullable|string',
                'address' => 'nullable|string',
            ]);

            //check if $validated['email']

            if ($validated['payment_method'] === 'mpesa_lipr' && empty($validated['lipr_wallet'])) {
                return response()->json([
                    'message' => 'lipr_wallet is required when payment method is mpesa_lipr.'
                ], 422);
            }

            if ($validated['payment_method'] === 'mpesa_mobile' && empty($validated['lipr_mobile_number'])) {
                return response()->json([
                    'message' => 'lipr_mobile_number is required when payment method is mpesa_mobile.'
                ], 422);
            }

            // Add user_id
            $validated['user_id'] = $userId;

            // Create supplier
            $supplier = SupplierDirectory::create($validated);

            //email
            $this->emailService->send(
                'You have been added as a Supplier on Tujitume',
                'programs.supplier_welcome',
                [
                    'recipientName'  => $validated['contact_person'] ?? $validated['legal_name'],
                    'recipientEmail' => $validated['email'],
                    'added_by'       => auth()->user()->fname . ' ' . auth()->user()->lname,
                    'org_name'       => auth()->user()->fname.' Funders',
                    'supplier_name'  => $validated['legal_name'],
                    'supplier_type'  => $validated['supplier_type'] ?? null,
                ],
                $validated['email']
            );


            DB::commit();

            return response()->json([
                'message' => 'Supplier added successfully',
                'data' => $supplier,
            ], 201);

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
     * Update supplier
     * PATCH /program/supplier-directory/{supplier_id}
     */
    public function update(Request $request, $supplierId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $supplier = SupplierDirectory::where('user_id', $userId)
                ->findOrFail($supplierId);

            $validated = $request->validate([
                // Basic Identity
                'legal_name' => 'sometimes|string|max:255',
                'contact_person' => 'nullable|string|max:150',
                'phone' => 'nullable|string|max:30',
                'email' => 'nullable|email|max:191',
                'supplier_type' => 'nullable|string|max:100',

                // Payment Method
                'payment_method' => 'nullable|in:mpesa_paybill,mpesa_till,mpesa_mobile,bank_transfer,other',

                // LIPR Details
                'lipr_wallet' => 'nullable|string|max:30',
                'lipr_mobile_number' => 'nullable|string|max:30',

                // M-Pesa Paybill
                'mpesa_paybill_number' => 'nullable|string|max:20',
                'mpesa_paybill_account' => 'nullable|string|max:20',

                // M-Pesa Till
                'mpesa_till_number' => 'nullable|string|max:20',

                // M-Pesa General
                'mpesa_account_reference' => 'nullable|string|max:100',

                // Bank Details
                'bank_name' => 'nullable|string|max:100',
                'bank_account_number' => 'nullable|string|max:50',
                'bank_branch' => 'nullable|string|max:100',
                'bank_swift_code' => 'nullable|string|max:20',

                // Internal
                'notes' => 'nullable|string',
                'is_active' => 'nullable|boolean',
            ]);

            $supplier->update($validated);

            DB::commit();

            return response()->json([
                'message' => 'Supplier updated successfully',
                'data' => $supplier->fresh(),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Supplier not found'], 404);

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
     * Delete (archive) supplier
     * DELETE /program/supplier-directory/{supplier_id}
     */
    public function destroy($supplierId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $supplier = SupplierDirectory::where('user_id', $userId)
                ->findOrFail($supplierId);

            // Check if supplier is assigned to any milestones
            $assignedCount = DB::table('milestone_suppliers')
                ->where('supplier_id', $supplierId)
                ->count();

            if ($assignedCount > 0) {
                return response()->json([
                    'error' => "Supplier is assigned to {$assignedCount} milestone(s) and cannot be deleted. You can deactivate instead."
                ], 422);
            }

            // Safe to delete
            $supplier->delete();

            DB::commit();

            return response()->json([
                'message' => 'Supplier deleted successfully'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Supplier not found'], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Deactivate supplier (soft archive)
     * POST /program/supplier-directory/{supplier_id}/deactivate
     */
    public function deactivate($supplierId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $supplier = SupplierDirectory::where('user_id', $userId)
                ->findOrFail($supplierId);

            $supplier->update(['is_active' => false]);

            DB::commit();

            return response()->json([
                'message' => 'Supplier deactivated successfully',
                'data' => $supplier->fresh()
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Supplier not found'], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Reactivate supplier
     * POST /program/supplier-directory/{supplier_id}/activate
     */
    public function activate($supplierId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $supplier = SupplierDirectory::where('user_id', $userId)
                ->findOrFail($supplierId);

            $supplier->update(['is_active' => true]);

            DB::commit();

            return response()->json([
                'message' => 'Supplier activated successfully',
                'data' => $supplier->fresh()
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Supplier not found'], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    public function assignToMilestone(Request $request, ProgramMilestone $milestone)
    {
        DB::beginTransaction();

        try {
            if(!$milestone->application){
                return response()->json([
                    'message' => 'No application found for this milestone.'
                ], 404);
            }
            $applicantId = $milestone->application->user_id;

            $addedBy = 'program_owner';

            // 🔐 Authorization - only program owner or applicant can assign suppliers to a milestone
            if ($applicantId === auth()->id()) {

                $allowedEdits = $milestone->allowed_edits ?? [];

                $addedBy = 'applicant';

                if (!in_array('can_add_suppliers', $allowedEdits)) {
                    return response()->json([
                        'message' => 'You are not permitted to add suppliers to this milestone'
                    ], 403);
                }
            }


            // ✅ Validation
            $validated = $request->validate([
                'supplier_id' => ['required', 'integer', 'exists:supplier_directories,id' ],
                'payment_route' => ['required', Rule::in(['direct_to_supplier', 'split', 'direct_to_applicant'])
                ],
                'quoted_amount' => ['required', 'numeric'],

                'assignment_type' => ['nullable', Rule::in(['primary', 'approved', 'preferred']) ],
            ]);

            $exists = MilestoneSupplier::where('milestone_id', $milestone->id)
                ->where('supplier_id', $validated['supplier_id'])->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Supplier already assigned to this milestone'
                ], 422);
            }

            // Update record
            $supplier = MilestoneSupplier::create([
                'milestone_id'   => $milestone->id,
                'supplier_id'     =>$validated['supplier_id'],
                'assignment_type'=> $validated['assignment_type'] ?? 'approved',
                'payment_route'  => $validated['payment_route'],
                'quoted_amount'      => $validated['quoted_amount'],
                'added_by'       => $addedBy,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Supplier assigned successfully',
                'data' => $supplier
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            DB::rollBack();

            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

}
