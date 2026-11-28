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
    protected $disbursementService;
    protected $balance;

    public function __construct(ProgramDisbursementService $disbursementService)
    {
        parent::__construct();
        $this->liprW2W = app(LiprW2W::class); // from the container, so a test or local stub can stand in for LIPR
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
        (new \App\Service\Program\ProgramAccess())->authorizeMilestone(auth()->user(), $milestone);
        $milestone->load([
            'application:id,program_id,user_id,program_owner_id,startup_name,contact_person_name,contact_person_email,status,funding_setup_status,awarded_amount,total_amount_requested,planning_mode',
            'application.program:id,program_title,program_type,funding_per_business',
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
            'supplier_name' => $this->supplierName($disbursement),
            'application_id'    => $disbursement->milestone->app_id,
            'milestone_number' => $disbursement->milestone->sequence_order,
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

            // The same result as the LIPR "successful" callback (MpesaCallbackController), for payments completed by hand
            $milestone = $disbursement->milestone;
            $disbursement->status = 'completed';
            $disbursement->disbursed_at = $disbursement->disbursed_at ?? now();
            $disbursement->supplier_id = $disbursement->supplier_id ?? $milestone->suppliers()->value('id');
            $disbursement->save();

            // Check if all disbursements for this milestone are completed
            $pendingCount = $milestone->disbursements()
                ->whereIn('status', ['pending', 'processing'])
                ->count();

            if ($pendingCount === 0) {
                // All disbursements completed
                $milestone->update(['fund_release_status' => 'released', 'status' => 'completed', 'fund_released' => true]);
                \App\Service\Program\ApplicationCompletion::refresh($milestone->application);
            }

            DB::commit();

            $this->programNotification->send('disbursement.completed', [$disbursement->milestone->application->user, ...\App\Service\Program\ProgramTeam::members($disbursement->milestone->application->program)->all()], [
                'amount' => $disbursement->amount, 'supplier_name' => $this->supplierName($disbursement),
                'payment_reference' => $disbursement->payment_reference, 'application_id' => $disbursement->milestone->app_id,
                'milestone_number' => $disbursement->milestone->sequence_order,
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


    /** The supplier's name for notifications; a disbursement made before it was linked to its supplier falls back to the milestone's */
    private function supplierName(Disbursement $disbursement): string
    {
        $supplier = $disbursement->supplier ?? $disbursement->milestone?->suppliers()->first();
        return (string) ($supplier?->supplierDirectory?->legal_name ?? '');
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

            $this->programNotification->send('disbursement.failed', \App\Service\Program\ProgramTeam::members($disbursement->milestone->application->program)->all(), [
                'supplier_name' => $this->supplierName($disbursement), 'reason' => $validated['failure_reason'],
                'application_id' => $disbursement->milestone->app_id,
                'milestone_number' => $disbursement->milestone->sequence_order,
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

            $this->programNotification->send('disbursement.reversed', [$disbursement->milestone->application->user, ...\App\Service\Program\ProgramTeam::members($disbursement->milestone->application->program)->all()], [
                'amount' => $disbursement->amount, 'supplier_name' => $this->supplierName($disbursement),
                'application_id' => $disbursement->milestone->app_id,
                'milestone_number' => $disbursement->milestone->sequence_order,
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


    // Milestone Release LIPR | from program wallet
    public function releaseFunds(Request $request, ProgramMilestone $milestone)
    {
        $userId      = auth()->id();
        $application = $milestone->application;

        $request->validate([
            'type' => 'required|string|in:pay_from_escrow,pay_from_wallet',
        ]);

        if ($application->program->user_id !== $userId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // applicant can fund the total application amount to escrow, then release milestone by milestone with type=pay_from_escrow
        if ($request->type == 'pay_from_escrow') {

            $escrowRecord = LiprPayment::where('reference_id', $application->escrow_funded_reference)
                ->whereIn('status', ['completed', 'SUCCESSFUL'])
                ->first();

            if (!$application->escrow_funded || !$escrowRecord) {
                return response()->json([
                    'message' => 'Funds not yet in escrow. Please complete payment first.'
                ], 422);
            }
        }

        if ($milestone->fund_release_status !== 'approved') {
            return response()->json([
                'message' => 'Milestone is not ready for fund release. Current status: ' . $milestone->fund_release_status
            ], 422);
        }

        if (!$milestone->canReleaseInSequence()) {
            return response()->json([
                'message' => 'Earlier milestones must be completed and approved before releasing funds for this milestone.',
            ], 422);
        }


        try {
            // ISSUE-28: a milestone with no supplier pays the applicant (beneficiary / hybrid disbursement)
            $supplier = $milestone->suppliers()->first();
            $route = $supplier?->payment_route ?? 'direct_to_applicant';
            if ($route === 'direct_to_applicant' && ! filled($milestone->application->sme?->lipr_wallet)) {
                return response()->json(['message' => 'The applicant has no M-Pesa (LIPR) wallet set up yet, so this milestone cannot be paid to them. Ask them to set one up, or add a supplier to the milestone.'], 422);
            }
            $pitch     = $milestone->application;
            $owner     = $pitch->program->owner;

            // ISSUE-22 (owner, 27 Nov): the supplier gets the full milestone amount; the transfer fee (platform + M-Pesa,
            // the same size as before) is paid by the program on top, from the program wallet
            $amountKes = (int) round((float) $milestone->amount);
            $feeKes = (int) round($amountKes - $this->checkoutCalculator->mpesaGC($amountKes, 'mpesa'));

            if ($request->type === 'pay_from_wallet') {
                // DECISION-5: the money is sent from the organization's main LIPR account; the program wallet is the
                // bookkeeping that must cover it
                $programWallet = $pitch->program->wallet;
                if (! \App\Service\Program\OrganizationMainWallet::account($pitch->program)) {
                    return response()->json(['message' => \App\Service\Program\OrganizationMainWallet::MISSING], 422);
                }
                if (! $programWallet) {
                    return response()->json(['message' => 'This program has no wallet yet, so funds cannot be released.'], 422);
                }
                if ((float) $programWallet->balance < $amountKes + $feeKes) {
                    return response()->json([
                        'message' => 'The program wallet balance (KES ' . number_format((float) $programWallet->balance, 2) . ') does not cover this release of KES '
                            . number_format($amountKes, 2) . ' plus the KES ' . number_format($feeKes, 2) . ' fee. Top up the program wallet and try again.',
                    ], 422);
                }
            }
    
            $transfer = null;

            DB::transaction(function () use (
                $milestone, $supplier, $route, $pitch, $amountKes, $feeKes, &$transfer, $request,
                $owner
                ) {

                // Initiate supplier transfer
                if ($route == 'direct_to_supplier') {

                    $transfer = $this->disbursementService->disburse($milestone, $amountKes, $request->type, $feeKes);
                
                } elseif ($route == 'direct_to_applicant') {
                   
                    if($request->type == 'pay_from_escrow') {
                        $transfer = $this->liprW2W->send(
                            $amountKes,
                            $pitch->sme->lipr_wallet,
                            $this->tujitume_lipr,
                            $milestone
                        );
                    }
                    elseif($request->type == 'pay_from_wallet') {
                        // DECISION-5: sent from the organization's main LIPR account; the program wallet is charged below
                        $transfer = $this->liprW2W->send(
                            $amountKes,
                            $pitch->sme->lipr_wallet,
                            \App\Service\Program\OrganizationMainWallet::account($pitch->program),
                            $milestone
                        );
                    }
                   
                
                } else {
                    throw new \Exception("Unsupported payment route: {$route}", 422);
                }

                if (!$transfer || !($transfer['success'] ?? false)) {
                    $reason = $transfer['error'] ?? $transfer['message'] ?? (is_array($transfer['errors'] ?? null) ? implode(' ', $transfer['errors']) : null);
                    throw new \Exception($reason ?: 'The transfer could not be started. Please try again.', 422);
                }

                // Paying the applicant from the program wallet: the program pays the amount and the fee (ISSUE-22)
                if ($route === 'direct_to_applicant' && $request->type === 'pay_from_wallet') {
                    $programWallet = $pitch->program->wallet;
                    $programWallet->balance -= $amountKes + $feeKes;
                    $programWallet->total_disbursed += $amountKes;
                    $programWallet->save();
                    $transfer['disbursement']?->update(['fee_amount' => $feeKes, 'recipient_type' => 'business_owner']);
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
                $this->transaction->create($pitch->program_owner_id, 'program_milestone', 'lipr', $amountKes, $transfer['reference'], $pitch->user_id);
                $this->transaction->create($pitch->user_id, 'program_milestone', 'lipr', $amountKes, $transfer['reference'], $pitch->user_id);

                // Balance update for direct_to_supplier if exists
                if ($route == 'direct_to_supplier' && $supplier?->supplierDirectory?->user) {
                    $this->balance->updateBalance($supplier->supplierDirectory->user_id, $amountKes, 'lipr');

                }
                else{
                    //update  balance directly to applicant
                    $this->balance->updateBalance($pitch->user_id, $amountKes, 'lipr');
                }
            });


            // Send email to supplier if direct disbursement

            if ($transfer !== null && $route === 'direct_to_supplier' && filled($supplier?->supplierDirectory?->email)) {
                $supplierDirectory = $supplier->supplierDirectory;
                $this->emailService->send(
                    'Your Payment Is on the Way',
                    'programs.disbursement_supplier_processing',
                    [
                        'recipientName' => $supplierDirectory->contact_person ?: $supplierDirectory->legal_name,
                        'supplier_name' => $supplierDirectory->legal_name,
                        'amount' => $transfer['disbursement']->amount,
                        'payment_reference' => $transfer['reference'] ?? null,
                    ],
                    $supplierDirectory->email
                );
            }

            // Notify
            $this->programNotification->send('disbursement.created', [
                $pitch->user,
                $pitch->program->owner,
            ], [
                'amount'         => $milestone->amount,
                'supplier_name'  => $supplier?->supplierDirectory?->legal_name ?? ($pitch->startup_name ?: 'the applicant'),
                'application_id' => $milestone->app_id,
                'milestone_number' => $milestone->sequence_order,
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
