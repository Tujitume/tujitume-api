<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\BusinessSubscriptions;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\CapitalProfile;
use App\Models\Communication\Messages;
use App\Models\Finance\BalanceLog;
use App\Models\Finance\LiprPayment;
use App\Models\Finance\Transactions;
use App\Models\Programs\Program;
use App\Models\Misc\Setting;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceMessages;
use App\Models\Services\Services;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\Balance\RetrieveBalanceService;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use App\Service\Subscription\SubscriptionHolder;
use Dotenv\Exception\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

class AccountController extends Controller
{
    protected $retriveBalance;
    protected $balance;
    protected $Client;
    protected $liprW2W;
    public function __construct(StripeClient $client)
    {
        parent::__construct();

        $this->Client = $client;

        $this->balance = new BalanceService();
        // From the container, so tests can stand in for LIPR and the exchange rate
        $this->liprW2W = app(LiprW2W::class);
        $this->lirpAuth = app(LiprAuthService::class);
        $this->convert = app(CurrencyConverter::class);
        $this->usdToKes = $this->convert->UsdToKes();
        $this->retriveBalance = app()->makeWith(RetrieveBalanceService::class, ['client' => $client]);
    }

    public function account_wallet(){
        $user = Auth::user();

        try{

            $user->load('balance');
            $userBalance = $user->balance?->balance ?? 0;

            $connected = ($user->connect_id && $user->completed_onboarding) ? 1 : 0;
            $lipr = $user->lipr_wallet ? 1 : 0;

            //Role User check
            if($user->user_type_id == 4){
                $user->load('organizationRole.role');
                $role = $user->organizationRole?->role?->name ?? null;
                if($role){
                    $owner_id = $user->organizationOwnerId();
                    $owner = User::findOrFail($owner_id);
                    $userBalance = $owner->balance?->balance ?? 0;

                    $connected = ($owner->connect_id && $owner->completed_onboarding) ? 1 : 0;
                    $lipr = $owner->lipr_wallet ? 1 : 0;
                }
            }
            else if((int) $user->user_type_id === 5){ // capital team member: the owner's account
                $user->load('capital_profile.role');
                $role = $user->capital_profile?->role?->name ?? null;
                if($role){
                    $owner_id = $user->capital_profile?->capital_owner_id;
                    $owner = User::findOrFail($owner_id);
                    $userBalance = $owner->balance?->balance ?? 0;

                    $connected = ($owner->connect_id && $owner->completed_onboarding) ? 1 : 0;
                    $lipr = $owner->lipr_wallet ? 1 : 0;
                }
            }

            $connectAccount = 'N/A'; $transferCapabilities = false;
            if($user->stripe_connect_id && $user->stripe_connect_id != 'null') {
                $connectAccount = $this->Client->accounts->retrieve($user->stripe_connect_id);
                $capabilities = $connectAccount->capabilities;

                if (
                    isset($capabilities['transfers']) && $capabilities['transfers'] === 'active' ||
                    isset($capabilities['crypto_transfers']) && $capabilities['crypto_transfers'] === 'active' ||
                    isset($capabilities['legacy_payments']) && $capabilities['legacy_payments'] === 'active'
                ) {
                    $transferCapabilities = true;
                }
            }
            else {
                //throw new \Exception('User does not have a connected Stripe account.');
            }

            return response()->json([
                'user' => [$user], 'balanceA'=>$userBalance, 'balanceP'=>0,
                'stripe_onboard'=>$connected, 'lipr_onboard' => $lipr,
                'connectAccount' => $connectAccount,
                'transferCapabilities' => $transferCapabilities,
            ]);

        }
        catch (\Exception $e) {
            $user = [Auth::user()];
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.',
                'user' => $user,'balanceA'=>0,'balanceP'=>0,'stripe_onboard'=>0,'lipr_onboard' => 0
            ], 500);
        }

    }

    public function transactions()
    {
        try{
            $user = Auth::user();
            $user_id = $user->id;

            if($user->user_type_id == 4){
                $role = $user->organizationRole?->role?->name;
                if($role){
                    $user_id = $user->organizationOwnerId();
                }
            }
            else if((int) $user->user_type_id === 5){ // capital team member: the owner's account
                $role = $user->capital_profile?->role?->name;
                if($role){
                    $user_id = $user->capital_profile?->capital_owner_id;
                }
            }

            // The Transactions page asks for a page (search, filters, sort, export): answered on the database.
            // scope (owner, 28 Nov): personal = your own money (My Wallet), organization = the organization wallet's
            // (anyone in the organization can look), all = both (My Transactions). Your own rows are yours, not the owner's.
            if (\App\Service\Account\TransactionList::wanted(request())) {
                $organization = (int) $user->user_type_id === 4
                    ? ($user->organization_id ? \App\Models\Organizations\Organization::find($user->organization_id) : \App\Models\Organizations\Organization::where('owner_user_id', $user->id)->first())
                    : null;
                $organizationId = $organization && \App\Service\Program\OrganizationMainWallet::canView($user, $organization) ? (int) $organization->id : null;

                return (new \App\Service\Account\TransactionList((int) ($organizationId ? $user->id : $user_id), $organizationId, (string) request('scope', 'all')))->respond(request());
            }

            $transactions = Transactions::with([
                'recipient',
                'user'
            ])->where(fn ($query) => (int) $user->user_type_id === 4
                // An organization person's recent list (My Wallet) is their own money, not the organization's or the owner's
                ? \App\Service\Account\TransactionList::personalTo($query, (int) $user->id)
                : \App\Service\Account\TransactionList::scopeTo($query, (int) $user_id))->latest()->get();

            foreach($transactions as $transaction){
                if($transaction->direction == 'debit' && $user_id == $transaction->recipient_id){
                    $transaction->direction = 'credit';
                }
            }

            return response()->json(['data' => $transactions], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function withdraws()
    {
        try{
            $user = Auth::user();
            $transactions = $user->transactions()->where('type','withdraw')->get();
            return response()->json(['data' => $transactions], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }



    // L i p r  S u b s c r i p t i o n
    public function lipr_subscription_initiate(Request $request, CurrencyConverter $converter)
    {
        $user = Auth::user();

        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!'],401);
        }

        try {
            $validated = $request->validate([
                'plan' => 'required|string|in:silver,gold,platinum',
                'trial' => 'nullable|boolean', //null / 0 / 1
                // wallet: pay from the LIPR wallet (refused when it is short: never an STK push to a placeholder);
                // mpesa: an STK push to acc_number (a phone). Without it: the old behaviour by the number given
                'method' => 'nullable|string|in:wallet,mpesa',
                'acc_number' => 'required_if:method,mpesa|nullable|string|max:20',
                'purpose' => 'required|string',
            ]);
            $method = $validated['method'] ?? (self::isPhone($validated['acc_number'] ?? '') ? 'mpesa' : 'wallet');

            // An organisation's subscription is bought by its owner or a super admin, for the whole team
            if (!SubscriptionHolder::canManage($user)) {
                return response()->json(['message' => 'Only your organisation\'s owner or a super admin can change its subscription.'], 403);
            }

            $existingSubscription = BusinessSubscriptions::where('investor_id', SubscriptionHolder::idFor($user))
                ->where('plan', $validated['plan'])->where('expire_date', '>', now())->exists();

            if ($existingSubscription) {
                return response()->json(['message' => 'You already have an active subscription for this plan.'], 409);
            }

            // ISSUE-52: a trial is decided here, not by the request: free (no money moves), 7 days, once per account / organisation
            if (! empty($validated['trial'])) {
                if (! self::trialAvailable($user)) {
                    return response()->json(['message' => 'The free trial has already been used. Choose a paid plan.'], 422);
                }
                $this->wallet_subscrition_db_update($validated['plan'], true, $user, null);

                return response()->json(['subscribe_via_wallet' => true, 'trial' => true, 'message' => 'Your 7-day free trial has started.'], 200);
            }

            $quote = $this->subscriptionQuote($validated['plan'], $user, $converter);
            if (isset($quote['error'])) {
                return response()->json(['message' => $quote['error']], $quote['code']);
            }
            $amountKes = $quote['amount_kes'];
            $platform_wallet = $quote['platform_wallet'];

            // Subscribe with the wallet balance (ISSUE-52: the real LIPR balance, not a hard-coded one)
            if ($method === 'wallet') {
                if (! $quote['wallet_ok']) {
                    return response()->json([
                        'subscribe_via_wallet' => false,
                        'message' => $quote['wallet_balance'] === null
                            ? 'We could not read your wallet balance. Pay with M-Pesa instead.'
                            : 'Your wallet has '.$quote['currency'].' '.number_format($quote['wallet_balance'], 2).'; this plan costs '.$quote['currency'].' '.number_format($amountKes, 2).'. Pay with M-Pesa instead.',
                    ], 422);
                }

                $transferResult = $this->liprW2W->send($amountKes, $platform_wallet, $user->lipr_wallet, null, ['narration' => 'Subscription payment']);
                if (empty($transferResult['success'])) {
                    return response()->json(['subscribe_via_wallet' => false, 'message' => 'The wallet payment did not go through: '.($transferResult['message'] ?? ($transferResult['errors'][0] ?? 'LIPR refused it')).'.'], 422);
                }

                $this->transaction->create($user->id, 'subscription', 'lipr', $amountKes, $transferResult['reference'] ?? null);
                $this->wallet_subscrition_db_update($validated['plan'], false, $user, $transferResult['reference'] ?? null);

                return response()->json([
                    'subscribe_via_wallet' => true,
                    'message' => 'Subscription payment successful via Lipr wallet balance.',
                    'data' => ['reference' => $transferResult['reference'] ?? null],
                ], 200);
            }

            if (! self::isPhone($validated['acc_number'] ?? '')) {
                return response()->json(['message' => 'Enter the M-Pesa phone number to pay from.'], 422);
            }
            $token = $this->lirpAuth->authorize();

            $base_path = config('services.lipr.base_path');

            $url = $base_path . "/partners/v1/payments/mobile-money/stk";

            $fields = [
                "requestId" => 'stk-' . now()->format('YmdHis') . '-' . uniqid(),
                "resultUrl" => \App\Service\LiprMpesa\LiprCallback::url('/lipr-callback'),
                "timeoutUrl" => \App\Service\LiprMpesa\LiprCallback::url('/lipr-callback/timeout'),
                //"metadata" => ["user_id" => $user->id, "purpose" => $request->purpose, //"customerId" => "CUST-441"],

                "wallet" => $platform_wallet,
                "narration" => $validated['purpose'],
                "recipients" => [
                    [
                        "amount" => $amountKes,
                        "account" => $validated['acc_number']
                    ]
                ]
            ];

            $fields_string = json_encode($fields);
            //open connection
            $ch = curl_init();
            curl_setopt($ch,CURLOPT_URL, $url);
            curl_setopt($ch,CURLOPT_POST, true);
            curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                "Authorization: Bearer ".$token,
                "Cache-Control: no-cache",
                'Content-Type: application/json'
            ));
            curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
            $result = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);
            $result = json_decode((string) $result, true);

            if ($curlError) {
                ErrorLogService::report(new \Exception('LIPR STK: '.$curlError));

                return response()->json(['message' => 'M-Pesa could not be reached. Please try again.'], 502);
            }

            // ISSUE-45 (same rule here): LIPR refused the push → say so with a 4xx, never 200 with nothing started
            $first = $result['data'][0] ?? null;
            if (($result['status'] ?? null) !== 200 || (is_array($first) && ($first['success'] ?? true) === false)) {
                $reason = $first['reason'] ?? $result['message'] ?? $result['error'] ?? 'M-Pesa refused the request';

                return response()->json(['subscribe_via_wallet' => false, 'message' => 'The M-Pesa payment could not start: '.$reason.'.'], 422);
            }

            // Which payment LIPR will call back about, and who started it (support, and the local test helper)
            \Illuminate\Support\Facades\Log::info('LIPR subscription payment started', [
                'reference' => $first['reference'] ?? $first['referenceId'] ?? $first['reference_id'] ?? null,
                'user_id' => $user->id, 'plan' => $validated['plan'], 'amount_kes' => $amountKes,
            ]);

            return response()->json([
                'subscribe_via_wallet' => false,
                'data' => $result,
                'message' => 'Check your phone to approve the M-Pesa payment.',
            ], 200);
        }
        catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);

            if (in_array($e->getCode(), [404, 422])) {
                return response()->json(['message' => $e->getMessage()], $e->getCode());
            }

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }

    }


    /**
     * ISSUE-53: what a plan costs and whether the wallet covers it, without moving any money (the checkout asks this when
     * the person picks "Wallet"; only Confirm charges).
     */
    public function lipr_subscription_quote(Request $request, CurrencyConverter $converter)
    {
        $validated = $request->validate(['plan' => 'required|string|in:silver,gold,platinum']);
        $user = Auth::user();
        if (! SubscriptionHolder::canManage($user)) {
            return response()->json(['message' => 'Only your organisation\'s owner or a super admin can change its subscription.'], 403);
        }

        try {
            $quote = $this->subscriptionQuote($validated['plan'], $user, $converter);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['plan' => $validated['plan']]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
        if (isset($quote['error'])) {
            return response()->json(['message' => $quote['error']], $quote['code']);
        }

        return response()->json(['data' => [
            'plan' => $validated['plan'],
            'amount_kes' => $quote['amount_kes'],
            'currency' => $quote['currency'],
            'wallet_balance' => $quote['wallet_balance'],
            'wallet_ok' => $quote['wallet_ok'],
            'trial_available' => self::trialAvailable($user),
        ]], 200);
    }

    /** The plan's price in KES with the M-Pesa charges, and the person's LIPR wallet balance (null when it cannot be read). */
    private function subscriptionQuote(string $plan, $user, CurrencyConverter $converter): array
    {
        $rate = $converter->UsdToKes('USD');
        if (! $rate || $rate <= 0) {
            throw new \Exception('Currency conversion rate unavailable');
        }
        $platform_wallet = Setting::where('key', 'platform_lipr_wallet')->value('value');
        if (! $platform_wallet) {
            return ['error' => 'Tujitume platform lipr account not found.', 'code' => 404];
        }
        $amountPayable = $this->checkoutCalculator->mpesa(config("subscriptions.plans.{$plan}"), 'mpesa');
        if (! $amountPayable || $amountPayable <= 0) {
            throw new \Exception('Invalid payment amount calculated');
        }
        // Whole shillings: M-Pesa refuses an amount with decimal places, so round up to the next shilling
        $amountKes = (float) ceil(round($amountPayable * $rate, 2));

        $balance = null;
        if ($user->lipr_wallet) {
            $lipr = $this->retriveBalance->lipr($user->id);
            $balance = ($lipr['success'] ?? false) ? \App\Service\Balance\RetrieveBalanceService::availableOf($lipr['data'] ?? null) : null;
        }

        return [
            'amount_kes' => $amountKes,
            'currency' => 'KES',
            'platform_wallet' => $platform_wallet,
            'wallet_balance' => $balance,
            'wallet_ok' => $balance !== null && $balance >= $amountKes,
        ];
    }

    /** A free trial is once per subscription holder (the person, or their organisation's owner). */
    public static function trialAvailable($user): bool
    {
        return ! BusinessSubscriptions::where('investor_id', SubscriptionHolder::idFor($user))->where('trial', 1)->exists();
    }

    private static function isPhone(?string $value): bool
    {
        return (bool) preg_match('/^\+?(254|0)?[17]\d{8}$/', preg_replace('/\s+/', '', (string) $value));
    }

    public function lipr_subscription(Request $request)
    {
        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!' ],401);
        }

        // A purely numeric reference may arrive as a JSON number; it is still a reference
        if (is_int($request->input('referenceId'))) {
            $request->merge(['referenceId' => (string) $request->input('referenceId')]);
        }

        try {
            $request->validate([
                'plan' => 'required|string|in:silver,gold,platinum',
                'trial' => 'nullable|boolean', //null / 0 / 1
                // LIPR references are letters and digits (e.g. "LKEC2M162"), not numbers
                'referenceId' => 'required|string|max:100',
            ]);
            $plan = $request->plan;
            $isTrial = false; // ISSUE-52: an M-Pesa payment is always a paid plan (free trials start without paying)
            $referenceId = $request->referenceId;

            $paymentExists = LiprPayment::where('reference_id', $referenceId)->first();
            if (!$paymentExists) {
                return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
            }

            if (strtolower((string) $paymentExists->status) === "failed") {
                return response()->json([
                    'status' => 'failed', 'updated_at' => now(),
                    'message' => 'Customer rejected payment or did not pay',
                ], 200);
            }
            // Already credited (the page polled again): the subscription exists, say so instead of an error
            if ($paymentExists->status === 'completed') {
                return response()->json(['status' => 'processed', 'updated_at' => now()], 200);
            }
            // LIPR's callback says "Successful"; older rows say "processed". Anything else: still waiting.
            if (! in_array(strtolower((string) $paymentExists->status), ['successful', 'processed'], true)) {
                return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
            }

            $user = Auth::user();

            // Transaction Begins:
            DB::transaction(function () use ($referenceId, $paymentExists,$isTrial, $plan, $user) {
                $payment = LiprPayment::where('reference_id', $referenceId)->lockForUpdate()->first();

                if ($payment->status === 'completed') {
                    throw new \Exception('Payment already completed.', 409);
                }

                if (! in_array(strtolower((string) $payment->status), ['successful', 'processed'], true)) {
                    throw new \Exception('Payment not ready for crediting.', 422);
                }

                // If Processed
                $start_date = now(); $duration = 30;

                // Plan setup
                $amount = config("subscriptions.plans.{$plan}") ?? $payment->amount_usd;
                $token_remaining = config("subscriptions.tokens.{$plan}") ?? 0;

                if ($isTrial) {
                    $amount = 0; $expire_date = now()->addDays(7);
                } else {
                    $expire_date = now()->addDays($duration);
                }

                BusinessSubscriptions::create([
                    'plan' => $plan,
                    'investor_id' => SubscriptionHolder::idFor($user),
                    'amount' => $amount,
                    'start_date' => $start_date,
                    'expire_date' => $expire_date,
                    'token_remaining' => $token_remaining,
                    'trial' => $isTrial,
                    'stripe_sub_id' => null
                ]);

                $amountKes = round($this->usdToKes * $amount, 2);

                //Transaction
                $this->transaction->create(
                    $user->id,'unlock_business','lipr', $amountKes, $referenceId
                );

                $payment->update(['status' => 'completed']);
            });

            // The subscribe page waits for 'processed'
            return response()->json([
                'status' => 'processed',
                'updated_at' => now(),
            ],200);
        }
        catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);

            if (in_array($e->getCode(), [404, 409, 422])) {
                return response()->json(['message' => $e->getMessage()], $e->getCode());
            }

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // H e l p e r s
    public function wallet_subscrition_db_update($plan, $isTrial, $user, $refId)
    {
        try {
            $user = Auth::user();

            // Transaction Begins:
            DB::transaction(function () use ($isTrial, $plan, $user) {

                // If Processed
                $start_date = now(); $duration = 30;

                $amount = config("subscriptions.plans.{$plan}") ?? 0;
                $token_remaining = config("subscriptions.tokens.{$plan}") ?? 0;

                if ($isTrial) {
                    $amount = 0; $expire_date = now()->addDays(7);
                } else {
                    $expire_date = now()->addDays($duration);
                }

                BusinessSubscriptions::create([
                    'plan' => $plan,
                    'investor_id' => SubscriptionHolder::idFor($user),
                    'amount' => $amount,
                    'method' => 'lipr',
                    'start_date' => $start_date,
                    'expire_date' => $expire_date,
                    'token_remaining' => $token_remaining,
                    'trial' => $isTrial,
                    'lipr_payment_id' => null
                ]);

                //$payment->update(['status' => 'completed']);
            });

            return response()->json([
                'status' => 'success',
                'updated_at' => now(),
            ],200);
        }
        catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            if (in_array($e->getCode(), [404, 409, 422])) {
                return response()->json(['message' => $e->getMessage()], $e->getCode());
            }
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


}
