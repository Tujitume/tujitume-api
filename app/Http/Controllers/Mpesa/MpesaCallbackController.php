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
use App\Models\Services\ServiceBookingMilestone;
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



    /**
     * DECISION-9: LIPR's result for a project manager audit fee transfer (AuditFee: metadata listingId "mv-<verification>"
     * or "sa-<service booking milestone>"). A transfer the app counted as sent that LIPR then failed is marked
     * release_failed (payout) so it shows and can be followed up; a successful one changes nothing.
     */
    public function callbackForAuditFee(Request $request)
    {
        $data = $this->extractCallbackData($request, 'LIPR AUDIT FEE CALLBACK');
        $listing = (string) (($request->input('metadata.listingId')) ?? ($request->input('metadata.listing_id')) ?? '');
        $failed = ! in_array(strtolower((string) $data['status']), ['successful', 'success', 'completed'], true);

        // mv = program check, sa = service booking, ca = capital milestone, pma = business audit. A failed hold or refund
        // transfer is never "released", so only a payout is moved back (the PM can then retry it).
        if ($failed && preg_match('/^(mv|sa|ca|pma)-(\d+)$/', $listing, $m)
            && \App\Service\Audits\AuditFee::payoutFailed($m[1], (int) $m[2], $data['referenceId'] ?: null)) {
            Log::warning('LIPR AUDIT FEE CALLBACK: payout failed', ['listing' => $listing, 'reference' => $data['referenceId']]);
        }

        return response()->json(['message' => 'Callback received'], 200);
    }

    /** A wallet transfer with no flow of its own (an unlock fee share, a bulk payout): its result is recorded and logged. */
    public function callbackForTransfer(Request $request, CurrencyConverter $convert)
    {
        try {
            $d = $this->extractCallbackData($request, 'LIPR TRANSFER CALLBACK');
            $this->recordLiprPayment($d['referenceId'], $d['transactionId'], strtolower($d['status'] ?? ''), $d['amount'], $convert->KesToUsd() * (float) $d['amount']);
            if (strtolower($d['status'] ?? '') !== 'successful') {
                Log::warning('LIPR transfer did not go through', ['reference' => $d['referenceId'], 'status' => $d['status'] ?? null]);
                // An organization wallet withdrawal to a Super Admin's My Wallet: the money goes back to the organization
                if (strtolower($d['status'] ?? '') === 'failed') {
                    \App\Service\Program\OrganizationWithdrawal::failed($d['referenceId'], (string) ($request->input('metadata.listingId') ?? $request->input('metadata.listing_id') ?? ''));
                }
            }

            return response()->json(['message' => 'Callback received'], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /** LIPR's timeout notice (ISSUE-45): logged only; the result callback or the status poll settles the payment. */
    public function timeout(Request $request)
    {
        Log::warning('LIPR TIMEOUT CALLBACK', ['payload' => $request->except(['sig'])]);

        return response()->json(['message' => 'Callback received'], 200);
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

            // The checkout wrote a pending row for this reference; only a payment LIPR already settled is a repeat callback
            $existingPayment = LiprPayment::where('reference_id', $referenceId)->whereRaw('LOWER(status) <> ?', ['pending'])->exists();
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
            $amountKes = round($amountToTransfer, 0);

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
                $this->transaction->create($pitch->program_owner_id, 'program_milestone', 'lipr', $amountKes, $referenceId, $pitch->user_id);
                $this->transaction->create($pitch->user_id, 'program_milestone', 'lipr', $amountKes, $referenceId, $pitch->user_id);

                // Balance update for direct_to_applicant
                if($supplier->payment_route == 'direct_to_supplier' && $supplier->supplierDirectory->user_id) {
                    $this->balance->updateBalance($supplier->supplierDirectory->user_id, $amountKes, 'lipr');
                }
                else if($supplier->payment_route == 'direct_to_applicant') {
                    $this->balance->updateBalance($pitch->user_id, $amountKes, 'lipr');
                }
            });

            // Notify
            $this->programNotification->send('disbursement.created', [
                $pitch->user,
                $pitch->program->owner,
                $supplier->supplierDirectory->user ?? null,
            ], [
                'amount'         => $milestone->amount,
                'supplier_name'  => $supplier->supplierDirectory->legal_name,
                'application_id' => $milestone->app_id,
                'milestone_number' => $milestone->sequence_order,
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

            // The checkout wrote a pending row for this reference; only a payment LIPR already settled is a repeat callback
            $existingPayment = LiprPayment::where('reference_id', $referenceId)->whereRaw('LOWER(status) <> ?', ['pending'])->exists();
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
                'escrow_funded_reference' => $referenceId,
                'escrow_funded_at' => now(),
                'escrow_amount'    => $application->total_amount_requested,
            ]);

            //Notify GO that funds are in escrow
                       $this->programNotification->send('disbursement.escrow_funded', [
                           $application->program->owner
                       ], [
                           'program_title' => $application->program->program_title,
                           'amount'      => number_format((float) $application->total_amount_requested),
                           'currency'    => $application->program->currency ?: 'KES',
                           'business_name' => $application->business?->name ?? $application->startup_name,
                           'application_id' => $application->id,
                       ]);

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

            // Record payment (paid by the program's owner)
            $payer = ProgramMilestone::with('application:id,program_owner_id')->find($listing_id)?->application?->program_owner_id;
            $this->recordLiprPayment(
                $referenceId,
                $transactionId,
                $status,
                $amount,
                $convert->KesToUsd() * $amount,
                $payer ? (int) $payer : null,
                'program_milestone'
            );

            $milestone   = ProgramMilestone::findOrFail($listing_id);

            if ($milestone->fund_released || $milestone->fund_release_status === 'released') {
                // A repeated callback still opens the next milestone if a paid one left it locked (safe to repeat)
                $milestone->unlockNextWhenFinished($milestone->application?->program_owner_id);
                Log::info('LIPR PROGRAM SUPPLIER CALLBACK: Already processed', ['reference_id' => $referenceId]);
                return response()->json(['message' => 'Callback received'], 200);
            }

            $pitch       = $milestone->application;
            $supplier    = $milestone->suppliers()->first();
            $supplierUser = $supplier?->supplierDirectory?->user;
            $disbursement = $milestone->disbursements()->latest()->first();
        

            if (strtolower($status) === 'successful') {
                DB::transaction(function () use ($milestone, $disbursement, $pitch, $supplier) {
                    // Update milestone
                    $milestone->update([
                        'fund_release_status' => 'released',
                        'status' => 'completed',
                        'fund_released'       => true,  // ← now truly released
                    ]);
                    // No final approval step: the next milestone opens now, or the plan would stay stuck on it
                    $milestone->unlockNextWhenFinished($pitch->program_owner_id ?? null);
                        // The application may now be in monitoring, or completed
                        \App\Service\Program\ApplicationCompletion::refresh($milestone->application ?? null);

                    // Update disbursement
                    $disbursement?->update([
                        'status'       => 'completed',
                        'disbursed_at' => now(),
                        "supplier_id" => $supplier?->id,
                    ]);

                    // Deduct from program wallet
                    $wallet = $pitch->program->wallet;

                });

                // Notify the applicant and the program team
                $this->programNotification->send('disbursement.completed', [$pitch->user, ...\App\Service\Program\ProgramTeam::members($pitch->program)->all()], [
                    'amount'        => $milestone->amount,
                    'supplier_name' => $supplier?->supplierDirectory->legal_name,
                    'application_id'    => $milestone->app_id,
                    'milestone_number' => $milestone->sequence_order,
                ]);

                // supplier invoice
                $token = hash('sha256', $disbursement->id . $disbursement->created_at . config('app.key'));

                if( $supplierUser ) {
                    $this->programNotification->send(
                        'disbursement.supplier_confirmed',
                        [$supplierUser],
                        [
                            'program_title'       => $pitch->program->program_title,
                            'amount'            => $amount,
                            'payment_reference' => $disbursement->payment_reference,
                            'disbursement_id'   => $disbursement->id,
                            'confirmation_token' => $token,
                        ]
                    );
                }
                


            } else {
                // The transfer failed: back to approved so it can be released again, and the balance credited when it was
                // sent is taken back (the supplier's, or the applicant's on a direct payout)
                // (only once: a repeated failure callback finds the disbursement already failed)
                $firstFailure = $milestone->fund_release_status === 'processing' && $disbursement && $disbursement->status !== 'failed';
                $milestone->update(['fund_release_status' => 'approved', 'status' => 'approved']);
                $disbursement?->update(['status' => 'failed']);
                if ($firstFailure) {
                    try {
                        $this->balance->updateBalanceMinus((int) ($supplierUser?->id ?? $pitch->user_id), (float) $amount, 'lipr');
                    } catch (\Throwable $e) {
                        ErrorLogService::report($e, ['milestone_id' => $milestone->id, 'reason' => 'could not take back the credit of a failed payout']);
                    }
                }

                // Notify the program team
                $this->programNotification->send('disbursement.failed', \App\Service\Program\ProgramTeam::members($pitch->program)->all(), [
                    'amount'        => $amount,
                    'supplier_name' => $supplier?->supplierDirectory->legal_name,
                    'reason'        => 'Supplier transfer failed',
                    'application_id'    => $milestone->app_id,
                    'milestone_number' => $milestone->sequence_order,
                ]);
            }

            return response()->json(['message' => 'Callback received'], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Callback for Reviewer Payment -(W2W Confirmation)
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

            $this->recordLiprPayment($referenceId, $transactionId, $status, $amount, $amountUsd);


            $order = ReviewerOrder::with('reviewer', 'program')->findOrFail($orderId);
            $wallet = $order->program->wallet;
            $owner = $order->program->owner;
            $reviewer = $order->reviewer;

            // Idempotency
            if ($order->payment_status === 'completed') {
                return response()->json(['message' => 'Already processed'], 200);
            }

            if ($status === 'successful') {
                DB::transaction(function () use ($order, $amount, $wallet, $reviewer) {
                    $order->update([
                        'payment_status' => 'completed',
                        'paid_at'        => now(),
                        'work_status'    => 'approved',
                        'approved_at'    => now(),
                    ]);

                    $wallet->balance = (float) $wallet->balance - $amount;
                    $wallet->total_disbursed = (float) $wallet->total_disbursed + $amount;
                    $wallet->save();

                    $this->balance->updateBalance(
                        $reviewer->id,
                        $amount,
                        'reviewer_payment'
                    );

                    
                });

                $this->transaction->create(
                    $owner->id,
                    'reviewer_payment_sent',
                    'lipr', // C9: paid through LIPR (M-Pesa), not Stripe
                    $amount,
                    $referenceId,
                    $reviewer->id
                );

                $this->transaction->create(
                    $reviewer->id,
                    'reviewer_payment_received',
                    'lipr', // C9: paid through LIPR (M-Pesa), not Stripe
                    $amount,
                    $referenceId,
                    $owner->id
                );



                // Notify reviewer that payment landed
                $this->programNotification->send('reviewer.payment_completed', [
                    $order->reviewer
                ], [
                    'program_title' => $order->program->program_title,
                    'amount'        => $order->fee_amount,
                    'currency'      => $order->currency,
                    'order_id'      => $order->id,
                ]);


            } else {
                // The transfer was refused; the owning order flow can retry after resolving the cause.
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

    /**
     * Lipr's answer to a service milestone release (ServiceBookingFlow::approve sends from Tujitume's wallet to the
     * provider's). Success credits the provider's balance; a failure puts the milestone back to waiting for approval,
     * so the customer can approve it again. Repeated callbacks change nothing.
     */
    public function callbackForServiceMilestone(Request $request, CurrencyConverter $convert)
    {
        try {
            $data = $this->extractCallbackData($request, 'LIPR SERVICE MILESTONE CALLBACK');
            $status = strtolower((string) ($data['status'] ?? ''));
            $milestone = $data['listingId'] ? ServiceBookingMilestone::with('booking')->find($data['listingId']) : null;
            if (! $milestone || $milestone->release_status !== 'processing') {
                return response()->json(['message' => 'Callback received'], 200);
            }

            if ($status === 'successful') {
                DB::transaction(function () use ($milestone) {
                    $milestone->update(['release_status' => 'completed']);
                    if ($milestone->booking) {
                        $this->balance->updateBalance((int) $milestone->booking->service_owner_id, (float) $milestone->amount, 'lipr');
                    }
                });
                // ISSUE-46: the provider hears when the money has arrived
                if ($milestone->booking) {
                    $this->notification->create((int) $milestone->booking->service_owner_id, null,
                        'KES '.number_format((float) $milestone->amount, 2)." for {$milestone->title} has reached your wallet.",
                        'dashboard.serviceProvider.serviceBookings', 'service');
                }
            } elseif (in_array($status, ['failed', 'cancelled', 'canceled', 'reversed', 'declined', 'expired'], true)) {
                $milestone->update(['released' => 0, 'release_status' => 'failed', 'released_at' => null]);
                // The release recorded at approval never reached the provider: it is not money in for them (a new approval
                // records its own), so their transactions and earnings do not count it twice
                if ($milestone->release_reference) {
                    \App\Models\Finance\Transactions::where('type', 'service_milestone')->where('reference_id', $milestone->release_reference)
                        ->update(['status' => 'failed']);
                }
                if ($milestone->booking?->status === 'completed') {
                    $milestone->booking->update(['status' => 'in_progress', 'stage' => 'Payment release failed']);
                }
                $this->notification->create((int) $milestone->booking?->booker_id, null,
                    "The payment for {$milestone->title} could not be sent to the provider. Nothing was paid; please approve it again.",
                    \App\Service\Services\ServiceBookingFlow::customerLinkKey($milestone->booking), 'service');
                // ISSUE-55: the provider sees a failed release too (it used to look approved and paid to them)
                if ($milestone->booking) {
                    $this->notification->create((int) $milestone->booking->service_owner_id, null,
                        "The payment for {$milestone->title} did not go through. The customer has been asked to approve it again.",
                        'dashboard.serviceProvider.serviceBookings', 'service');
                }
            }

            return response()->json(['message' => 'Callback received'], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
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

    private function recordLiprPayment($referenceId, $transactionId, $status, $amount, $amountUsd, ?int $payerId = null, string $purpose = 'transfer'): LiprPayment
    {
        // The checkout already wrote a pending row for this reference (who paid, for what): update it, keeping those.
        // A wallet transfer has no such row: it is created here, for the payer when known (user_id cannot be empty; it
        // used to fail, so the transfer callbacks never settled anything)
        $payment = LiprPayment::firstOrNew(['reference_id' => $referenceId]);
        // A repeated callback for a payment already used (credited / released) must not make it usable again
        if ($payment->exists && strtolower((string) $payment->status) === 'completed') {
            return $payment;
        }
        if (! $payment->exists) {
            $payment->user_id ??= $payerId ?? 0;
            $payment->purpose ??= $purpose;
        }
        $payment->fill([
            'transaction_id' => $transactionId,
            'status' => $status,
            'amount' => $amount,
            'amount_usd' => $amountUsd,
        ])->save();

        return $payment;
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
