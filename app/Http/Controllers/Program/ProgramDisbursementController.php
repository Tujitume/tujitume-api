<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Finance\LiprPayment;
use App\Models\Programs\Disbursement;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\MilestoneSupplier;
use App\Models\Misc\Setting;
use App\Service\Balance\BalanceService;
use App\Service\LiprMpesa\ProgramDisbursementService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\LiprMpesa\MpesaTransfer;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgramDisbursementController extends Controller
{
    protected $liprW2W;
    protected $mpesaTransfer;
    protected $tujitume_lipr;
    public function __construct(ProgramDisbursementService $disbursementService)
    {
        parent::__construct();
        $this->liprW2W = new LiprW2W();
        $this->balance = new BalanceService();
        $this->disbursementService = $disbursementService;
        $this->tujitume_lipr = Setting::where('key', 'platform_lipr_wallet')->first()?->value ?? null;
        $this->mpesaTransfer = new MpesaTransfer();
    }

    /**
     * Lightweight endpoint for the disbursement page.
     * GET /program/milestones/{milestone}/disbursement-data
     */
    public function disbursementData(ProgramMilestone $milestone)
    {
        $milestone->load([
            'application:id,program_id,user_id,program_owner_id,startup_name,contact_person_name,contact_person_email,status,funding_setup_status,awarded_amount,total_amount_requested,planning_mode',
            'application.program:id,program_title,program_type,funding_per_business,mid_milestone_required',
            'application.program.wallet:id,program_id,balance,total_reserved,total_disbursed,status',
            'suppliers',
            'budgetItems',
            'verifications',
            'disbursements',
        ]);

        $budgetItems = $milestone->budgetItems;
        $totalAllocated = $budgetItems->sum('total_cost');
        $milestoneAmount = (float) $milestone->amount;

        return response()->json([
            'milestone' => $milestone,
            'pitch' => $milestone->application,
            'wallet' => $milestone->application->program->wallet ?? null,
            'budget_summary' => [
                'milestone_amount' => $milestoneAmount,
                'total_allocated' => $totalAllocated,
                'remaining_amount' => max(0, $milestoneAmount - $totalAllocated),
                'item_count' => $budgetItems->count(),
            ],
        ]);
    }


    public function supplierConfirm(Request $request, Disbursement $disbursement)
    {
        // Verify token
        $expectedToken = hash('sha256', $disbursement->id . $disbursement->created_at . config('app.key'));

        if ($request->token !== $expectedToken) {
            return response()->json(['message' => 'Invalid confirmation token'], 403);
        }

        if ($disbursement->supplier_confirmed) {
            return response()->json(['message' => 'Already confirmed'], 200);
        }

        $disbursement->update([
            'supplier_confirmed'    => true,
        ]);

        // Notify program owner
        $this->programNotification->send('disbursement.completed', [
            $disbursement->milestone->application->program->owner
        ], [
            'amount'        => $disbursement->amount,
            'supplier_name' => $disbursement->supplier->supplierDirectory->legal_name,
        ]);

        return response()->json(['message' => 'Receipt confirmed successfully. Thank you!'], 200);
    }


    //  Unused below

    /**
     * Store a newly created resource in storage.
     */

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

    public function markCompleted(Request $request, Disbursement $disbursement)
    {
        $userId = auth()->id();

        // Authorization: only program owner can mark as completed
        if ($disbursement->milestone->application->program_owner_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check current status
        if ($disbursement->status === 'completed') {
            return response()->json([
                'message' => 'Disbursement already marked as completed',
                'data' => $disbursement,
            ]);
        }

        if (!in_array($disbursement->status, ['pending', 'processing'])) {
            return response()->json([
                'error' => 'Can only complete pending or processing disbursements. Current status: ' . $disbursement->status
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'receipt_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
                'referenceId' => 'nullable|string|max:100', // if method is mpesa lipr mobile
            ]);

            if(isset($validated['referenceId'])) {
                $payment = LiprPayment::where('reference_id', $validated['referenceId'])->first();

                if (!$payment) {
                    return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
                }

                if ($payment->status === 'completed') {
                    throw new \Exception('Payment already completed.', 409);
                }

                if ($payment->status !== 'processed') {
                    throw new \Exception('Payment not ready for crediting.', 422);
                }
            }

            // Handle receipt upload if provided
            if ($request->hasFile('receipt_file')) {
                $path = 'files/disbursementReceipts/' . $disbursement->milestone_id;
                $validated['receipt_file'] = $this->fileUpload->saveFile(
                    $request->file('receipt_file'),
                    $path
                );
                $disbursement->receipt_file = $validated['receipt_file'];
            }

            $disbursement->status = 'completed';
            $disbursement->save();

            // Check if all disbursements for this milestone are completed
            $milestone = $disbursement->milestone;
            $pendingCount = $milestone->disbursements()
                ->whereIn('status', ['pending', 'processing'])
                ->count();

            if ($pendingCount === 0) {
                // All disbursements completed
                $milestone->fund_release_status = 'released';
                $milestone->save();
            }

            DB::commit();

            $this->programNotification->send('disbursement.completed', [$disbursement->milestone->application->user, $disbursement->milestone->application->program->owner], [
                'amount' => $disbursement->amount, 'supplier_name' => $disbursement->supplier->legal_name,
                'payment_reference' => $disbursement->payment_reference, 'application_id' => $disbursement->milestone->app_id,
            ]);

            // If all disbursements complete
            if ($milestone->fund_release_status === 'released') {
                $this->programNotification->send('milestone.funds_released', [$milestone->application->user], [
                    'milestone_number' => $milestone->sequence_order, 'application_id' => $milestone->app_id,
                ]);
            }

            return response()->json([
                'message' => 'Disbursement marked as completed',
                'data' => $disbursement,
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


    # Failed & Reverse
    public function markFailed(Request $request, Disbursement $disbursement)
    {
        $userId = auth()->id();

        // Authorization: only program owner can mark as failed
        if ($disbursement->milestone->application->program_owner_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check current status
        if ($disbursement->status === 'failed') {
            return response()->json([
                'message' => 'Disbursement already marked as failed',
                'data' => $disbursement,
            ]);
        }

        if (!in_array($disbursement->status, ['pending', 'processing'])) {
            return response()->json([
                'error' => 'Can only fail pending or processing disbursements. Current status: ' . $disbursement->status
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'failure_reason' => 'required|string',
            ]);

            $disbursement->status = 'failed';
            $disbursement->failure_reason = $validated['failure_reason'];
            $disbursement->save();

            // Return funds to reserved (not disbursed)
            $wallet = $disbursement->wallet;
            $wallet->total_reserved += $disbursement->amount;
            $wallet->total_disbursed -= $disbursement->amount;
            $wallet->save();

            DB::commit();

            $this->programNotification->send('disbursement.failed', [$disbursement->milestone->application->program->owner], [
                'supplier_name' => $disbursement->supplier->legal_name, 'reason' => $validated['failure_reason'],
                'application_id' => $disbursement->milestone->app_id,
            ]);

            return response()->json([
                'message' => 'Disbursement marked as failed. Funds returned to reserved.',
                'data' => $disbursement,
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
     * Reverse a completed disbursement
     * PATCH /api/v1/disbursements/{disbursement}/reverse
     */
    public function reverse(Request $request, Disbursement $disbursement)
    {
        $userId = auth()->id();

        // Authorization: only program owner can reverse
        if ($disbursement->milestone->application->program_owner_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Can only reverse completed disbursements
        if ($disbursement->status !== 'completed') {
            return response()->json([
                'error' => 'Can only reverse completed disbursements. Current status: ' . $disbursement->status
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'reversal_reason' => 'required|string',
            ]);

            // Update disbursement
            $disbursement->status = 'reversed';
            $disbursement->failure_reason = 'REVERSED: ' . $validated['reversal_reason'];
            $disbursement->save();

            // Return funds to reserved
            $wallet = $disbursement->wallet;
            $wallet->total_reserved += $disbursement->amount;
            $wallet->total_disbursed -= $disbursement->amount;
            $wallet->save();

            // Update milestone fund release status if needed
            $milestone = $disbursement->milestone;
            if ($milestone->fund_release_status === 'released') {
                $milestone->fund_release_status = 'processing';
                $milestone->save();
            }

            DB::commit();

            $this->programNotification->send('disbursement.reversed', [$disbursement->milestone->application->user, $disbursement->milestone->application->program->owner], [
                'amount' => $disbursement->amount, 'supplier_name' => $disbursement->supplier->legal_name,
                'application_id' => $disbursement->milestone->app_id,
            ]);

            return response()->json([
                'message' => 'Disbursement reversed. Funds returned to wallet.',
                'data' => $disbursement,
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

    // Milestone Release
    public function releaseFunds(Request $request, ProgramMilestone $milestone)
    {
        $userId      = auth()->id();
        $application = $milestone->application;

        if ($application->program->user_id !== $userId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if (!$application->escrow_funded) {
            return response()->json([
                'message' => 'Funds not yet in escrow. Please complete payment first.'
            ], 422);
        }

        if ($milestone->fund_release_status !== 'approved') {
            return response()->json([
                'message' => 'Milestone is not ready for fund release. Current status: ' . $milestone->fund_release_status
            ], 422);
        }

        try {
            $supplier = $milestone->suppliers()->first();

            if (!$supplier) {
                return response()->json(['message' => 'Supplier not found.'], 422);
            }
            $pitch     = $milestone->application;

            $amountToTransfer = $this->checkoutCalculator->mpesaGC($milestone->amount, 'mpesa');
            $amountKes = round($this->usdToKes * $amountToTransfer, 2);

            $transfer = null;

            DB::transaction(function () use ($milestone, $supplier, $pitch, $amountKes, &$transfer) {

                // Initiate supplier transfer
                if ($supplier->payment_route == 'direct_to_supplier') {
                    $transfer = $this->disbursementService->disburse($milestone, $amountKes, 'checkout');
                } elseif ($supplier->payment_route == 'direct_to_applicant') {
                    $transfer = $this->liprW2W->send(
                        $amountKes, $pitch->sme->lipr_wallet, $this->tujitume_lipr, $milestone
                    );
                } else {
                    throw new \Exception("Unsupported payment route: {$supplier->payment_route}", 422);
                }

                if (!$transfer || !($transfer['success'] ?? false)) {
                    throw new \Exception($transfer['error'] ?? $transfer['message'] ?? 'Transfer failed', 422);
                }


                // Update milestone
                $milestone->update([
                    'fund_release_status' => 'processing',
                    'status' => 'disbursing',
                ]);

                // Save transfer reference on disbursement
                $disbursement = $transfer['disbursement'];
                $disbursement->update([
                    'status'    => 'processing',
                    'payment_reference' => $transfer['reference'] ?? null,
                ]);

                // Transactions
                $this->transaction->create($pitch->program_owner_id, 'program_milestone', 'lipr', $milestone->amount, $transfer['reference'], $pitch->user_id);
                $this->transaction->create($pitch->user_id, 'program_milestone', 'lipr', $milestone->amount, $transfer['reference'], $pitch->user_id);

                // Balance update for direct_to_applicant
                if ($supplier->payment_route == 'direct_to_applicant') {
                    $this->balance->updateBalance($pitch->user_id, $milestone->amount, 'lipr');
                }
            });

            // Notify
            $this->programNotification->send('disbursement.created', [
                $pitch->user,
                $pitch->program->owner,
            ], [
                'amount'         => $milestone->amount,
                'supplier_name'  => $supplier->supplierDirectory->legal_name,
                'application_id' => $milestone->app_id,
            ]);

            return response()->json([
                'message'   => 'Funds release initiated successfully.',
                'reference' => $transfer['reference'] ?? null,
            ], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            if (in_array($e->getCode(), [422])) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

}
