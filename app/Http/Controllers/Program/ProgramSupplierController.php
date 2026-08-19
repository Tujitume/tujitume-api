<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\MilestoneBudgetItem;
use App\Models\Programs\MilestoneSupplier;
use App\Models\Programs\SupplierDirectory;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgramSupplierController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ProgramMilestone $milestone)
    {
        $userId = auth()->id();

        try {
            $suppliers = MilestoneSupplier::with('supplierDirectory')
            ->where('milestone_id', $milestone->id)->get();

            return response()->json(['suppliers' => $suppliers], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
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


    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $userId = auth()->id();

        try {
            $supplier = MilestoneSupplier::with('supplierDirectory')
                ->where('supplier_id', $id)->first();

            return response()->json(['data' => $supplier], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Supplier not found'], 404);

        } catch (\Exception $e) {
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
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

    public function budget_item_index(ProgramMilestone $milestone)
    {

        try {
            // Get budget items directly
            $budgetItems = $milestone->budgetItems()->latest()->get();

            // Calculations (use DB for efficiency)
            $totalAllocated = $milestone->budgetItems()->sum('total_cost');
            $milestoneAmount = $milestone->amount;
            $remainingAmount = $milestoneAmount - $totalAllocated;

            // Conditions
            $budgetMatches = (float)$totalAllocated === (float)$milestoneAmount;
            $suppliersExist = $milestone->suppliers()->exists();
            $mprvReady = $budgetMatches && $suppliersExist;

            return response()->json([
                'budget_items' => $budgetItems,

                'summary' => [
                    'milestone_amount' => $milestoneAmount,
                    'total_allocated' => $totalAllocated,
                    'remaining_amount' => $remainingAmount,
                ],

                'status' => [
                    'budget_matches' => $budgetMatches,
                    'suppliers_exist' => $suppliersExist,
                    'mprv_ready' => $mprvReady,
                    'current_status' => $milestone->status,
                ],
            ]);

        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'milestone_id' => $milestone->id,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }


    public function budget_item_store(Request $request, ProgramMilestone $milestone)
    {
        $applicantId = $milestone->application->user_id;

        $addedBy = 'program_owner';
        // 🔐 Authorization - only program owner or applicant can assign suppliers to a milestone
        if ($applicantId == auth()->id()) {

            $allowedEdits = $milestone->allowed_edits ?? [];

            $addedBy = 'applicant';

            if (!in_array('can_add_budget_items', $allowedEdits)) {
                return response()->json([
                    'message' => 'You are not permitted to add suppliers to this milestone'
                ], 403);
            }
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                // Required fields
                'item_description' => 'required|string|max:500',
                'unit_cost' => 'required|numeric|min:0',
                'quantity' => 'required|numeric|min:0.01',

                // Optional fields
                'supplier_id' => 'nullable|exists:milestone_suppliers,id',
                'purpose_type' => 'nullable|in:capex,opex,services,mixed',
            ]);

            // Auto-calculate total_cost
            $validated['total_cost'] = $validated['unit_cost'] * $validated['quantity'];
            $validated['milestone_id'] = $milestone->id;
            $validated['added_by'] = $addedBy;

            // Validate total doesn't exceed milestone amount
            $currentTotal = MilestoneBudgetItem::where('milestone_id', $milestone->id)->sum('total_cost');
            $newTotal = $currentTotal + $validated['total_cost'];

            if ($newTotal > $milestone->amount) {
                throw ValidationException::withMessages([
                    'total_cost' => ['Total budget items (' . $newTotal . ') cannot exceed milestone amount (' . $milestone->amount . ')']
                ]);
            }

            $budgetItem = MilestoneBudgetItem::create($validated);
            $milestone = $budgetItem->milestone;

            // Check if ready for MPRV
            $suppliersExist = $milestone->suppliers()->count() > 0;
            $budgetTotal = $milestone->budgetItems()->sum('total_cost');
            $budgetMatches = $budgetTotal == $milestone->amount;

//            if ($budgetMatches) {
//                $milestone->mprv_ready = true;
//                $milestone->save();
//            }

            DB::commit();
            return response()->json([
                'message' => 'Budget item added successfully',
                'data' => $budgetItem,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

}
