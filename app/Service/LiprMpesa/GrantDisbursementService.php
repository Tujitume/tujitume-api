<?php
namespace App\Service\LiprMpesa;

use App\Models\Grants\Disbursement;
use App\Models\Grants\GrantMilestone;
use App\Models\Grants\GrantWallet;
use App\Models\Grants\MilestoneSupplier;
use App\Models\Grants\SupplierDirectory;
use App\Models\Misc\Setting;
use App\Service\Balance\CheckoutAmountCalculator;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\GrantNotificationService;
use AWS\CRT\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class GrantDisbursementService
{
    public function __construct(
        private LiprPaymentService      $lipr,
        private GrantNotificationService $grantNotification,
    ) {}

    // ─── Main Entry Point ───────────────────────────────────────────────

    public function disburseToSupplier(
        Disbursement $disbursement,
        GrantMilestone $milestone,
        $sourceWallet,
        $amountKes,
    ): array {
        $milestoneSupplier = $milestone->suppliers()->first() ?? null;

        if( !$milestoneSupplier ){
            throw new \Exception('Milestone supplier not found', 422);
        }

        if (!$sourceWallet) {
            throw new \Exception('Source wallet not configured.', 422);
        }

        $supplier = $milestoneSupplier->supplierDirectory ?? null;

        // Validate before disbursing
        $this->validateDisbursement($disbursement, $milestone, $sourceWallet, $supplier);

        // Route to correct payment method
        $response = $this->routePayment($milestone->id, $supplier, $amountKes, $sourceWallet);

        if (!$this->lipr->isSuccess($response)) {
            throw new \Exception($this->lipr->getError($response));
        }

        // Update disbursement with reference
        $referenceId = $this->lipr->getReferenceId($response);
        \Illuminate\Support\Facades\Log::info('Ref:',  ['ref' => $referenceId]);

        $disbursement->update([
            'payment_reference' => $referenceId,
            'status'            => 'pending', // pending until callback confirms
        ]);

        return $response;
    }

    // ─── Route to correct LIPR payment method based on supplier ────────

    private function routePayment($milestoneId, SupplierDirectory $supplier,  float $amountKes, string $walletAccount): array
    {
        return match($supplier->payment_method) {
            'mpesa_mobile' => $this->lipr->toMobile(
                $milestoneId,
                $walletAccount,
                $supplier->lipr_mobile_number ?? $supplier->phone,
                $amountKes
            ),

            'mpesa_paybill' => $this->lipr->toPaybill(
                $walletAccount,
                $supplier->mpesa_paybill_number,
                $supplier->mpesa_paybill_account,
                $amountKes
            ),

            'mpesa_till' => $this->lipr->toTill(
                $walletAccount,
                $supplier->mpesa_till_number,
                $amountKes
            ),

            'bank_transfer' => $this->lipr->toBankTransfer(
                $walletAccount,
                $supplier->bank_account_number,
                $supplier->bank_name,
                $amountKes,
                $supplier->bank_swift_code
            ),

            'mpesa_lipr' => $this->lipr->toLiprWallet(
                $walletAccount,
                $supplier->lipr_wallet,
                $amountKes
            ),

            default => throw new \Exception("Unsupported payment method: {$supplier->payment_method}"),
        };
    }

    // ─── Mark Completed (called from callback) ──────────────────────────

    public function markCompleted(Disbursement $disbursement, GrantWallet $wallet): void
    {
        DB::transaction(function () use ($disbursement, $wallet) {
            $disbursement->update([
                'status'       => 'completed',
                'disbursed_at' => now(),
            ]);

            // Deduct from wallet reserved & disbursed
            $wallet->update([
                'total_reserved'  => $wallet->total_reserved - $disbursement->amount,
                'total_disbursed' => $wallet->total_disbursed + $disbursement->amount,
            ]);
        });

        // Notify grant owner
        $this->grantNotification->send('disbursement.completed', [
            $disbursement->milestone->application->grant->owner
        ], [
            'amount'        => $disbursement->amount,
            'supplier_name' => $disbursement->milestoneSupplier->supplierDirectory->legal_name,
        ]);
    }

    // ─── Mark Failed ────────────────────────────────────────────────────

    public function markFailed(Disbursement $disbursement, GrantWallet $wallet): void
    {
        DB::transaction(function () use ($disbursement, $wallet) {
            $disbursement->update(['status' => 'failed']);

            // Return reserved funds back to balance
            $wallet->update([
                'total_reserved' => $wallet->total_reserved - $disbursement->amount,
                'balance'        => $wallet->balance + $disbursement->amount,
            ]);
        });

        // Notify grant owner
        $this->grantNotification->send('disbursement.failed', [
            $disbursement->milestone->application->grant->owner
        ], [
            'amount'        => $disbursement->amount,
            'supplier_name' => $disbursement->milestoneSupplier->supplierDirectory->legal_name,
            'reason'        => 'Payment failed or rejected',
        ]);
    }

    // ─── Reverse ────────────────────────────────────────────────────────

    public function reverse(Disbursement $disbursement, GrantWallet $wallet): void
    {
        if ($disbursement->status !== 'completed') {
            throw new \Exception('Only completed disbursements can be reversed', 422);
        }

        DB::transaction(function () use ($disbursement, $wallet) {
            $disbursement->update(['status' => 'reversed']);

            // Return funds to wallet
            $wallet->update([
                'total_disbursed' => $wallet->total_disbursed - $disbursement->amount,
                'balance'         => $wallet->balance + $disbursement->amount,
            ]);
        });

        // Notify
        $this->grantNotification->send('disbursement.reversed', [
            $disbursement->milestone->application->grant->owner
        ], [
            'amount'        => $disbursement->amount,
            'supplier_name' => $disbursement->milestoneSupplier->supplierDirectory->legal_name,
        ]);
    }

    // ─── Validation ─────────────────────────────────────────────────────

    public function validateDisbursement(
        Disbursement $disbursement,
        GrantMilestone $milestone,
        $wallet,
        SupplierDirectory $supplier,
    ): void {
        if ($milestone->fund_release_status !== 'approved') {
            //throw new \Exception('Milestone funds have not been approved for release yet', 422);
        }

        if ($disbursement->status !== 'pending') {
            throw new \Exception('Disbursement is not in pending state', 422);
        }

//        if ($wallet->balance < $disbursement->amount) {
//            throw new \Exception('Insufficient wallet balance for this disbursement', 422);
//        }

        if (!$supplier->is_active) {
            throw new \Exception('Supplier is not active', 422);
        }
    }

    // Initiate Disbursement
    public function disburse(GrantMilestone $milestone, $amountKes, $type = 'checkout')
    {
        if($type == 'checkout'){
            $sourceWallet = Setting::where('key', 'platform_lipr_wallet')->value('value');
        }
        elseif ($type == 'pay_from_wallet'){
            $sourceWallet = $wallet = $milestone->application->grant->wallet->lipr_wallet ?? null;
        }

        if ($milestone->fund_release_status !== 'approved') {
//            throw new \Exception(
//                'Milestone must be approved for disbursement. Current status: ' . $milestone->fund_release_status,
//                422);
        }


        // Get wallet
        $disburseAmount = $milestone->amount;


        // Set defaults
        $validated = [];
        $validated['amount'] = $disburseAmount;
        $validated['milestone_id'] = $milestone->id;
        $validated['wallet_id'] = 1;
        $validated['recipient_type'] = 'supplier';
        $validated['payment_method'] = 'mpesa_mobile';
        $validated['currency'] = 'KES';
        $validated['status'] = 'pending';
        $validated['authorized_by'] = $milestone->application->grant_owner_id;

        $disbursement = Disbursement::create($validated);


        # L I P R / M P E S A Transfer to Supplier acc/wallet
        try {
            $response = $this->disburseToSupplier($disbursement, $milestone, $sourceWallet, $amountKes);

            // Update wallet (move from reserved to disbursed)
            if($type == 'pay_from_wallet'){
                $wallet->balance -= $disburseAmount;
                $wallet->total_disbursed += $disburseAmount;
                $wallet->save();
            }

            $reference = $this->lipr->getReferenceId($response);

            return [
                'success' => true,
                'disbursement' => $disbursement,
                'response' => $response,
                'reference' => $reference,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'disbursement' => $disbursement ?? null,
            ];
        }

    }

}
