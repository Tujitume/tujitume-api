<?php

namespace App\Http\Controllers\Mpesa;

use App\Http\Controllers\Controller;
use App\Models\Finance\LiprPayment;
use App\Models\Grants\GrantMilestone;
use App\Models\Misc\Setting;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\LiprMpesa\GrantDisbursementService;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MpesaCallbackController extends Controller
{

    public function __construct(GrantDisbursementService $disbursementService)
    {

        parent::__construct();

        $this->balance = new BalanceService();
        $this->liprW2W = new LiprW2W();
        $this->disbursementService = $disbursementService;
        $this->tujitume_lipr = Setting::where('key', 'platform_lipr_wallet')->first()->value ?? null;
    }

    public function auth()
    {
        try {
            $liprAuth = new LiprAuthService();
            $token = $liprAuth->authorize(); return $token;
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

    public function callback(Request $request, CurrencyConverter $convert)
    {
        try {
            //$liprService = new LiprAuthService();
            Log::info('LIPR CALLBACK', [
                'payload' => $request->all(),
                'raw' => $request->getContent(),
            ]);

            $provided_ip = '13.51.220.45';
//            if ($request->ip() !== $provided_ip) {
//                return response()->json(['message' => 'Forbidden'], 403);
//            }

            // Extract necessary data from the request
            $payload = $request->all();

            if (empty($payload)) {
                $payload = json_decode($request->getContent(), true) ?? [];
            }

            $transaction = $payload['transaction'] ?? [];

            $referenceId = $transaction['reference'] ?? null;
            $transactionId = $transaction['id'] ?? null;
            $status = $transaction['transactionStatus'] ?? null;
            $amount = $transaction['amount'] ?? 0;
            $requestId = $payload['requestId'] ?? null;

            $kesToUsd = $convert->KesToUsd();
            $amountUsd = $kesToUsd*$amount;


            $lipr = LiprPayment::create([
                'reference_id' => $referenceId,
                'transaction_id' => $transactionId,
                'status' => $status,
                'amount' => $amount,
                'amount_usd' => $amountUsd,
            ]);


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
    public function callbackGrantEscrow(Request $request, CurrencyConverter $convert)
    {
        try {
            Log::info('LIPR GRANT CALLBACK', [
                'payload' => $request->all(),
                'raw'     => $request->getContent(),
            ]);

            $payload     = !empty($request->all()) ? $request->all() : json_decode($request->getContent(), true) ?? [];
            $transaction = $payload['transaction'] ?? [];

            $referenceId   = $transaction['reference'] ?? null;
            $transactionId = $transaction['id'] ?? null;
            $status        = $transaction['transactionStatus'] ?? null;
            $amount        = $transaction['amount'] ?? 0;
            //$listing_id    = $payload['metadata']['listingId'] ?? null;

            $metadata = $payload['metadata'] ?? ''; preg_match('/listingId=(\d+)/', $metadata, $matches);

            $listing_id = $matches[1] ?? null;

            $kesToUsd = $convert->KesToUsd();
            $amountUsd = $kesToUsd * $amount;

            $existingPayment = LiprPayment::where('reference_id', $referenceId)->exists();
            if ($existingPayment) {
                return response()->json(['message' => 'Callback already received'], 200);
            }

            // Record payment
            $payment = LiprPayment::create([
                'reference_id'   => $referenceId,
                'transaction_id' => $transactionId,
                'status'         => $status,
                'amount'         => $amount,
                'amount_usd'     => $amountUsd,
            ]);

            // Only proceed if payment successful
            if (strtolower($status) !== 'successful') {
                return response()->json(['message' => 'Callback received'], 200);
            }

            $milestone = GrantMilestone::findOrFail($listing_id);

            // check duplicate callback
            $existingCompleted = LiprPayment::where('reference_id', $referenceId)
                ->where('status', 'completed')->exists();

            if ($existingCompleted || $milestone->fund_release_status !== 'approved') {
                Log::info('LIPR GRANT CALLBACK: Already processed', ['reference_id' => $referenceId]);
                return response()->json(['message' => 'Callback received'], 200);
            }

            $application = $milestone->application;

            $application->update([
                'escrow_funded'    => true,
                'escrow_funded_at' => now(),
                'escrow_amount'    => $application->total_amount_requested,
            ]);

            // Notify GO that funds are in escrow
//            $this->grantNotification->send('disbursement.escrow_funded', [
//                $application->grant->owner
//            ], [
//                'grant_title' => $application->grant->grant_title,
//                'amount'      => $application->total_amount_requested,
//            ]);

            return response()->json(['message' => 'Callback received'], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    public function callbackGrantDirectDisburse(Request $request, CurrencyConverter $convert)
    {
        try {
            Log::info('LIPR GRANT CALLBACK', [
                'payload' => $request->all(),
                'raw'     => $request->getContent(),
            ]);

            $payload     = !empty($request->all()) ? $request->all() : json_decode($request->getContent(), true) ?? [];
            $transaction = $payload['transaction'] ?? [];

            $referenceId   = $transaction['reference'] ?? null;
            $transactionId = $transaction['id'] ?? null;
            $status        = $transaction['transactionStatus'] ?? null;
            $amount        = $transaction['amount'] ?? 0;
            //$listing_id    = $payload['metadata']['listingId'] ?? null;

            $metadata = $payload['metadata'] ?? ''; preg_match('/listingId=(\d+)/', $metadata, $matches);

            $listing_id = $matches[1] ?? null;

            $kesToUsd = $convert->KesToUsd();
            $amountUsd = $kesToUsd * $amount;

            $existingPayment = LiprPayment::where('reference_id', $referenceId)->exists();
            if ($existingPayment) {
                return response()->json(['message' => 'Callback already received'], 200);
            }

            // Record payment
            $payment = LiprPayment::create([
                'reference_id'   => $referenceId,
                'transaction_id' => $transactionId,
                'status'         => $status,
                'amount'         => $amount,
                'amount_usd'     => $amountUsd,
            ]);

            // Only proceed if payment successful
            if (strtolower($status) !== 'successful') {
                return response()->json(['message' => 'Callback received'], 200);
            }

            $milestone = GrantMilestone::findOrFail($listing_id);

            // check duplicate callback
            $existingCompleted = LiprPayment::where('reference_id', $referenceId)
                ->where('status', 'completed')->exists();

            if ($existingCompleted || $milestone->fund_release_status !== 'approved') {
                Log::info('LIPR GRANT CALLBACK: Already processed', ['reference_id' => $referenceId]);
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

            $pitch->grant->decrement('available_amount', $milestone->amount);

            if (!$supplier) {
                throw new \Exception('Supplier not found.', 500);
            }

            $amountToTransfer = $this->checkoutCalculator->mpesaGC($milestone->amount, 'mpesa');
            $amountKes = round($this->usdToKes * $amountToTransfer, 2);

            DB::transaction(function () use ($milestone, $supplier, $pitch, $payment, $amountKes, $referenceId) {

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
                $this->transaction->create($pitch->grant_owner_id, 'grant_milestone', 'lipr', $payment->amount, $referenceId, $pitch->user_id);
                $this->transaction->create($pitch->user_id, 'grant_milestone', 'lipr', $payment->amount, $referenceId, $pitch->user_id);

                // Balance update for direct_to_applicant
                if ($supplier->payment_route == 'direct_to_applicant') {
                    $this->balance->updateBalance($pitch->user_id, $milestone->amount, 'lipr');
                }
            });

            // Notify
            $this->grantNotification->send('disbursement.created', [
                $pitch->user,
                $pitch->grant->owner,
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


    public function callbackForGrantSupplier(Request $request, CurrencyConverter $convert)
    {
        try {
            Log::info('LIPR GRANT SUPPLIER CALLBACK', [
                'payload' => $request->all(),
                'raw'     => $request->getContent(),
            ]);

            $payload     = !empty($request->all()) ? $request->all() : json_decode($request->getContent(), true) ?? [];
            $transaction = $payload['transaction'] ?? [];

            $referenceId   = $transaction['reference'] ?? null;
            $transactionId = $transaction['id'] ?? null;
            $status        = strtolower($transaction['transactionStatus'] ?? '');
            $amount        = $transaction['amount'] ?? 0;
            //$listing_id    = $payload['metadata']['listingId'] ?? null;

            $metadata = $payload['metadata'] ?? ''; preg_match('/listingId=(\d+)/', $metadata, $matches);

            $listing_id = $matches[1] ?? null;


            // Record payment
            LiprPayment::create([
                'reference_id'   => $referenceId,
                'transaction_id' => $transactionId,
                'status'         => $status,
                'amount'         => $amount,
                'amount_usd'     => $convert->KesToUsd() * $amount,
            ]);

            $milestone   = GrantMilestone::findOrFail($listing_id);

            if ($milestone->fund_released || $milestone->fund_release_status === 'released') {
                Log::info('LIPR GRANT SUPPLIER CALLBACK: Already processed', ['reference_id' => $referenceId]);
                return response()->json(['message' => 'Callback received'], 200);
            }

            $pitch       = $milestone->application;
            $supplier    = $milestone->suppliers()->first();
            $disbursement = $milestone->disbursements()->latest()->first();

            if ($status === 'successful') {
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

                    // Deduct from grant wallet
                    $wallet = $pitch->grant->wallet;
//                    $wallet->update([
//                        'total_reserved'  => $wallet->total_reserved - $milestone->amount,
//                        'total_disbursed' => $wallet->total_disbursed + $milestone->amount,
//                    ]);
                });

                // Notify
                $this->grantNotification->send('disbursement.completed', [
                    $pitch->user,
                    $pitch->grant->owner,
                ], [
                    'amount'        => $milestone->amount,
                    'supplier_name' => $supplier?->supplierDirectory->legal_name,
                ]);

                // supplier invoice
                $token = hash('sha256', $disbursement->id . $disbursement->created_at . config('app.key'));

                $this->grantNotification->send('disbursement.supplier_confirmed', [$supplier->supplierDirectory], [
                    'grant_title'       => $pitch->grant->grant_title,
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
                $this->grantNotification->send('disbursement.failed', [
                    $pitch->grant->owner,
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

}
