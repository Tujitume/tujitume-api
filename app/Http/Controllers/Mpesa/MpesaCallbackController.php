<?php

namespace App\Http\Controllers\Mpesa;

use App\Http\Controllers\Controller;
use App\Models\Finance\LiprPayment;
use App\Models\Programs\ProgramMilestone;
use App\Models\ReviewerOrder;
use App\Models\Misc\Setting;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\LiprMpesa\ProgramDisbursementService;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\ProgramNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MpesaCallbackController extends Controller
{
    protected $balance;
    protected $liprW2W;
    protected $disbursementService;
    protected $tujitume_lipr;
    protected $programNotification;

    public function __construct(ProgramDisbursementService $disbursementService)
    {

        parent::__construct();

        $this->balance = new BalanceService();
        $this->liprW2W = new LiprW2W();
        $this->disbursementService = $disbursementService;
        $this->tujitume_lipr = Setting::where('key', 'platform_lipr_wallet')->first()?->value ?? null;
        $this->programNotification = new ProgramNotificationService();
    }



    public function callback(Request $request, CurrencyConverter $convert)
    {
        try {
            $callbackData = $this->extractCallbackData($request, 'LIPR CALLBACK');
            $provided_ip = '13.51.220.45';
//            if ($request->ip() !== $provided_ip) {
//                return response()->json(['message' => 'Forbidden'], 403);
//            }

            $referenceId = $callbackData['referenceId'];
            $transactionId = $callbackData['transactionId'];
            $status = $callbackData['status'];
            $amount = $callbackData['amount'];
            $requestId = $callbackData['requestId'];

            $kesToUsd = $convert->KesToUsd();
            $amountUsd = $kesToUsd*$amount;

            $lipr = $this->recordLiprPayment($referenceId, $transactionId, $status, $amount, $amountUsd);

            return response()->json(['message' => 'Callback received'], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            if (in_array($e->getCode(), [404, 422])) {
                return response()->json(['message' => $e->getMessage()], $e->getCode());
            }

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }


    // direct to applicant or direct to supplier immeadiately after checkout
    public function callbackProgramDisburseDirect(Request $request, CurrencyConverter $convert)
    {
        try {
            $callbackData = $this->extractCallbackData($request, 'LIPR PROGRAM CALLBACK');
            $referenceId = $callbackData['referenceId'];
            $transactionId = $callbackData['transactionId'];
            $status = $callbackData['status'];
            $amount = $callbackData['amount'];
            $listing_id = $callbackData['listingId'];

            $kesToUsd = $convert->KesToUsd();
            $amountUsd = $kesToUsd * $amount;

            $existingPayment = LiprPayment::where('reference_id', $referenceId)->exists();
            if ($existingPayment) {
                return response()->json(['message' => 'Callback already received'], 200);
            }

            // Record payment
            $payment = $this->recordLiprPayment($referenceId, $transactionId, $status, $amount, $amountUsd);

            // Only proceed if payment successful
            if (strtolower($status) !== 'successful') {
                return response()->json(['message' => 'Callback received'], 200);
            }

            $milestone = ProgramMilestone::findOrFail($listing_id);

            // check duplicate callback
            $existingCompleted = LiprPayment::where('reference_id', $referenceId)
                ->where('status', 'completed')->exists();

            if ($existingCompleted || $milestone->fund_release_status !== 'approved') {
                Log::info('LIPR PROGRAM CALLBACK: Already processed', ['reference_id' => $referenceId]);
                return response()->json(['message' => 'Callback received'], 200);
            }

            $application = $milestone->application;

            $application->update([
                'escrow_funded'    => true,
                'escrow_funded_at' => now(),
                'escrow_amount'    => $application->total_amount_requested,
            ]);


            $supplier  = $milestone->suppliers()->first();
            $pitch     = $milestone->application;

            $pitch->program->decrement('available_amount', $milestone->amount);

            if (!$supplier) {
                throw new \Exception('Supplier not found.', 500);
            }

            $amountToTransfer = $this->checkoutCalculator->mpesaGC($milestone->amount, 'mpesa');
            $amountKes = 20; //round($amountToTransfer, 0);

            DB::transaction(function () use ($milestone, $supplier, $pitch, $payment, $amountKes, $referenceId) {

                // Initiate supplier transfer
                if ($supplier->payment_route == 'direct_to_supplier')
                {
                    // Disburse for supplier
                    $transfer = $this->disbursementService->disburse($milestone, $amountKes, 'checkout');
                }
                elseif ($supplier->payment_route == 'direct_to_applicant')
                {
                    // Disburse Wallet to Wallet for applicant
                    $transfer = $this->liprW2W->send(
                        $amountKes, $pitch->sme->lipr_wallet, $this->tujitume_lipr, $milestone
                    );
                }
                else
                {
                    throw new \Exception("Unsupported payment route: {$supplier->payment_route}", 422);
                }

                if (!$transfer || !($transfer['success'] ?? false)) {
                    throw new \Exception($transfer['error'] ?? $transfer['message'] ?? 'Transfer failed', 422);
                }

                // Update payment
                $payment->update(['status' => 'completed']);

                // Update milestone
                $milestone->update([
                    'fund_release_status' => 'processing',
                    'status' => 'disbursing',
                    //'fund_released'       => true,
                ]);

                // Save transfer reference on disbursement
                $disbursement = $transfer['disbursement'];
                $disbursement->update([
                    'status'    => 'processing',
                    'payment_reference' => $transfer['reference'] ?? null,
                ]);

                // Transactions
                $this->transaction->create($pitch->program_owner_id, 'program_milestone', 'lipr', $payment->amount, $referenceId, $pitch->user_id);
                $this->transaction->create($pitch->user_id, 'program_milestone', 'lipr', $payment->amount, $referenceId, $pitch->user_id);

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

            return response()->json(['message' => 'Callback received'], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    // to pay full application (total milestones sum) to escrow, then release milestone by milestone
    public function callbackProgramDisburseToEscrow(Request $request, CurrencyConverter $convert)
    {
        try {
            $callbackData = $this->extractCallbackData($request, 'LIPR PROGRAM CALLBACK');
            $referenceId = $callbackData['referenceId'];
            $transactionId = $callbackData['transactionId'];
            $status = $callbackData['status'];
            $amount = $callbackData['amount'];
            $listing_id = $callbackData['listingId'];

            $kesToUsd = $convert->KesToUsd();
            $amountUsd = $kesToUsd * $amount;

            $existingPayment = LiprPayment::where('reference_id', $referenceId)->exists();
            if ($existingPayment) {
                return response()->json(['message' => 'Callback already received'], 200);
            }

            // Record payment
            $payment = $this->recordLiprPayment($referenceId, $transactionId, $status, $amount, $amountUsd);

            // Only proceed if payment successful
            if (strtolower($status) !== 'successful') {
                return response()->json(['message' => 'Callback received'], 200);
            }

            $milestone = ProgramMilestone::findOrFail($listing_id);

            // check duplicate callback
            $existingCompleted = LiprPayment::where('reference_id', $referenceId)
                ->where('status', 'completed')->exists();

            if ($existingCompleted || $milestone->fund_release_status !== 'approved') {
                Log::info('LIPR PROGRAM CALLBACK: Already processed', ['reference_id' => $referenceId]);
                return response()->json(['message' => 'Callback received'], 200);
            }

            $application = $milestone->application;

            $application->update([
                'escrow_funded'    => true,
                'escrow_funded_at' => now(),
                'escrow_amount'    => $application->total_amount_requested,
            ]);

            // Notify GO that funds are in escrow
            //            $this->programNotification->send('disbursement.escrow_funded', [
            //                $application->program->owner
            //            ], [
            //                'program_title' => $application->program->program_title,
            //                'amount'      => $application->total_amount_requested,
            //            ]);

            return response()->json(['message' => 'Callback received'], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    public function callbackForProgramDisburseToSupplier(Request $request, CurrencyConverter $convert)
    {
        try {
            $callbackData = $this->extractCallbackData($request, 'LIPR PROGRAM SUPPLIER CALLBACK');
            $referenceId = $callbackData['referenceId'];
            $transactionId = $callbackData['transactionId'];
            $status = strtolower($callbackData['status'] ?? '');
            $amount = $callbackData['amount'];
            $listing_id = $callbackData['listingId'];


            // Record payment
            $this->recordLiprPayment(
                $referenceId,
                $transactionId,
                $status,
                $amount,
                $convert->KesToUsd() * $amount
            );

            $milestone   = ProgramMilestone::findOrFail($listing_id);

            if ($milestone->fund_released || $milestone->fund_release_status === 'released') {
                Log::info('LIPR PROGRAM SUPPLIER CALLBACK: Already processed', ['reference_id' => $referenceId]);
                return response()->json(['message' => 'Callback received'], 200);
            }

            $pitch       = $milestone->application;
            $supplier    = $milestone->suppliers()->first();
            $disbursement = $milestone->disbursements()->latest()->first();

            if (strtolower($status) === 'successful') {
                DB::transaction(function () use ($milestone, $disbursement, $pitch, $supplier) {
                    // Update milestone
                    $milestone->update([
                        'fund_release_status' => 'released',
                        'status' => 'completed',
                        'fund_released'       => true,  // ← now truly released
                    ]);

                    // Update disbursement
                    $disbursement?->update([
                        'status'       => 'completed',
                        'disbursed_at' => now(),
                        "supplier_id" => $supplier?->id,
                    ]);

                    // Deduct from program wallet
                    $wallet = $pitch->program->wallet;

                });

                // Notify
                $this->programNotification->send('disbursement.completed', [
                    $pitch->user,
                    $pitch->program->owner,
                ], [
                    'amount'        => $milestone->amount,
                    'supplier_name' => $supplier?->supplierDirectory->legal_name,
                ]);

                // supplier invoice
                $token = hash('sha256', $disbursement->id . $disbursement->created_at . config('app.key'));

                $this->programNotification->send('disbursement.supplier_confirmed', [$supplier->supplierDirectory], [
                    'program_title'       => $pitch->program->program_title,
                    'amount'            => $milestone->amount,
                    'payment_reference' => $disbursement->payment_reference,
                    'disbursement_id'   => $disbursement->id,
                    'confirmation_token'=> $token,
                ]);


            } else {
                // Supplier transfer failed
                $milestone->update(['fund_release_status' => 'approved']); // ← revert back to approved so it can be retried
                $disbursement?->update(['status' => 'failed']);

                // Notify owner
                $this->programNotification->send('disbursement.failed', [
                    $pitch->program->owner,
                ], [
                    'amount'        => $milestone->amount,
                    'supplier_name' => $supplier?->supplierDirectory->legal_name,
                    'reason'        => 'Supplier transfer failed',
                ]);
            }

            return response()->json(['message' => 'Callback received'], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Callback for Reviewer Payment - Leg 1 (STK Push)
     */
    public function callbackForReviewerPayment(Request $request, CurrencyConverter $convert)
    {
        try {
            $callbackData = $this->extractCallbackData($request, 'LIPR REVIEWER PAYMENT CALLBACK');
            $referenceId = $callbackData['referenceId'];
            $transactionId = $callbackData['transactionId'];
            $status = strtolower($callbackData['status'] ?? '');
            $amount = $callbackData['amount'];
            $orderId = $callbackData['listingId'];

            $kesToUsd  = $convert->KesToUsd();
            $amountUsd = $kesToUsd * $amount;

            // Record payment (Leg 1)
            $this->recordLiprPayment($referenceId, $transactionId, $status, $amount, $amountUsd);

            if ($status !== 'successful') {
                // Payment failed — update order
                ReviewerOrder::where('id', $orderId)
                    ->update(['payment_status' => 'failed']);

                return response()->json(['message' => 'Callback received'], 200);
            }

            $order    = ReviewerOrder::with('reviewer', 'program')->findOrFail($orderId);
            $reviewer = $order->reviewer;

            if (!$reviewer->lipr_wallet) {
                Log::error('Reviewer has no LIPR wallet', ['reviewer_id' => $reviewer->id, 'order_id' => $orderId]);
                return response()->json(['message' => 'Callback received'], 200);
            }

            // Idempotency check
            if ($order->payment_status === 'completed') {
                return response()->json(['message' => 'Callback received — already processed'], 200);
            }

            // ─── Leg 2: W2W Transfer to reviewer wallet ───────────────────

            $tujitumeWallet = Setting::where('key', 'platform_lipr_wallet')->value('value');

            $transfer = $this->liprW2W->send(
                $amount,           // amount in KES
                $reviewer->lipr_wallet,
                $tujitumeWallet,
                null               // no milestone context
            );

            if (!$transfer || !($transfer['success'] ?? false)) {
                Log::error('W2W transfer failed for reviewer payment', [
                    'order_id' => $orderId,
                    'error'    => $transfer['error'] ?? 'unknown',
                ]);
                $order->update(['payment_status' => 'failed']);
                return response()->json(['message' => 'Callback received'], 200);
            }

            // Update order with leg 2 reference
            $order->update([
                'payment_status' => 'leg1_processing',
                'leg1_reference' => $referenceId,
                'leg2_reference' => $transfer['reference'] ?? null,
            ]);

            return response()->json(['message' => 'Callback received'], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Callback for Reviewer Payment - Leg 2 (W2W Confirmation)
     */
    public function callbackForReviewerPaymentLeg2(Request $request, CurrencyConverter $convert)
    {
        try {
            $callbackData = $this->extractCallbackData($request, 'LIPR REVIEWER PAYMENT LEG2 CALLBACK');
            $referenceId = $callbackData['referenceId'];
            $status = strtolower($callbackData['status'] ?? '');
            $amount = $callbackData['amount'];
            $orderId = $callbackData['listingId'];

            $order = ReviewerOrder::with('reviewer', 'program')->findOrFail($orderId);

            // Idempotency
            if ($order->payment_status === 'completed') {
                return response()->json(['message' => 'Already processed'], 200);
            }

            if ($status === 'successful') {
                DB::transaction(function () use ($order, $amount, $convert) {
                    $order->update([
                        'payment_status' => 'completed',
                        'paid_at'        => now(),
                        'work_status'    => 'approved',
                        'approved_at'    => now(),
                    ]);
                });

                // Notify reviewer that payment landed
                $this->programNotification->send('reviewer.payment_completed', [
                    $order->reviewer
                ], [
                    'program_title' => $order->program->program_title,
                    'amount'        => $order->fee_usd,
                    'currency'      => $order->currency,
                    'order_id'      => $order->id,
                ]);

            } else {
                // W2W failed — revert to failed
                $order->update(['payment_status' => 'failed']);

                $this->programNotification->send('reviewer.payment_failed', [
                    $order->program->owner
                ], [
                    'program_title' => $order->program->program_title,
                    'reviewer_name' => $order->reviewer->first_name,
                    'order_id'      => $order->id,
                ]);
            }

            return response()->json(['message' => 'Callback received'], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    // P R I V A T E    H E L P E R    M E T H O D S

    private function extractListingId($metadata): ?int
    {
        if (is_array($metadata)) {
            $listingId = $metadata['listingId'] ?? $metadata['listing_id'] ?? null;

            if (is_int($listingId) && $listingId > 0) {
                return $listingId;
            }

            return is_string($listingId) && ctype_digit($listingId) && (int) $listingId > 0
                ? (int) $listingId
                : null;
        }

        if (is_string($metadata) && preg_match('/listingId=(\d+)/i', $metadata, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function extractCallbackData(Request $request, string $logMessage): array
    {
        Log::info($logMessage, [
            'payload' => $request->all(),
            'raw' => $request->getContent(),
        ]);

        $payload = $request->all();
        if (empty($payload)) {
            $payload = json_decode($request->getContent(), true) ?? [];
        }

        $transaction = $payload['transaction'] ?? [];

        return [
            'referenceId' => $transaction['reference'] ?? null,
            'transactionId' => $transaction['id'] ?? null,
            'status' => $transaction['transactionStatus'] ?? null,
            'amount' => $transaction['amount'] ?? 0,
            'requestId' => $payload['requestId'] ?? null,
            'listingId' => $this->extractListingId($payload['metadata'] ?? null),
        ];
    }

    private function recordLiprPayment($referenceId, $transactionId, $status, $amount, $amountUsd): LiprPayment
    {
        return LiprPayment::create([
            'reference_id' => $referenceId,
            'transaction_id' => $transactionId,
            'status' => $status,
            'amount' => $amount,
            'amount_usd' => $amountUsd,
        ]);
    }


    public function auth()
    {
        try {
            $liprAuth = new LiprAuthService();
            $token = $liprAuth->authorize();
            return $token;
        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);
            if (in_array($e->getCode(), [404, 422])) {
                return response()->json(['message' => $e->getMessage()], $e->getCode());
            }

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

}
