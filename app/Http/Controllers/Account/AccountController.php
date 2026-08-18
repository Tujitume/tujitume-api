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
use App\Models\Grants\Grant;
use App\Models\Grants\GrantProfile;
use App\Models\Misc\Setting;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceMessages;
use App\Models\Services\Services;
use App\Service\Account\AccountDeletionEligibilityService;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\Balance\RetrieveBalanceService;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
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
        $this->liprW2W = new LiprW2W();
        $this->lirpAuth = new LiprAuthService();
        $this->convert = new CurrencyConverter();
        $this->usdToKes = $this->convert->UsdToKes();
        $this->retriveBalance = new RetrieveBalanceService($client);
    }

    public function account_wallet(){
        $user = Auth::user();

        try{

            $user->load('balance');
            $userBalance = $user->balance?->balance ?? 0;

            $connected = ($user->connect_id && $user->completed_onboarding) ? 1 : 0;
            $lipr = $user->lipr_wallet ? 1 : 0;

            //Role User check
            if($user->user_type_id == 2){
                $user->load('grant_profile.role');
                $role = $user->grant_profile?->role?->name ?? null;
                if($role){
                    $owner_id = $user->grant_profile?->grant_owner_id;
                    $owner = User::findOrFail($owner_id);
                    $userBalance = $owner->balance?->balance ?? 0;

                    $connected = ($owner->connect_id && $owner->completed_onboarding) ? 1 : 0;
                    $lipr = $owner->lipr_wallet ? 1 : 0;
                }
            }
            else if($user->user_type_id == 3){
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
            if($user->connect_id && $user->connect_id != 'null') {
                $connectAccount = $this->Client->accounts->retrieve($user->connect_id);
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

            if($user->user_type_id == 2){
                $role = $user->grant_profile?->role?->name;
                if($role){
                    $user_id = $user->grant_profile?->grant_owner_id;
                }
            }
            else if($user->user_type_id == 3){
                $role = $user->capital_profile?->role?->name;
                if($role){
                    $user_id = $user->capital_profile?->capital_owner_id;
                }
            }

            $transactions = Transactions::with([
                'recipient',
                'user'
            ])->where(function ($query) use ($user_id) {
                $query->where('user_id', $user_id)
                    ->orWhere('recipient_id', $user_id);
            })->latest()->get();

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

    // Delete account
    public function destroy($id)
    {
        $user = User::with('balance')->find($id);
        $type = $user->user_type_id;

        if($id != Auth::id()){
            return response(['message' => 'Unauthorized.'],401);
        }

        if($user->balance?->balance > 0){
            return response(['message' => 'Account deletion not allowed. Please withdraw your balance first.'],400);
        }

        //deletion eligibility checks
        $checker = new AccountDeletionEligibilityService($user);

        if( !$checker->isDeletable() ){
            return response()->json([
                'message' => 'Account deletion is not allowed',
                'reasons' => $checker->preventingReason(),
            ], 400);

        }

        //return response(['message' => 'Deletion begins...'],200);

        DB::beginTransaction();
        try{

            //Balance logs
            BalanceLog::where('changed_by', $user->id)->delete();

            if($type == 1){
                // Delete all investor files & active investment
                BusinessBids::where('investor_id', $id)->delete();
                AcceptedBids::where('investor_id', $id)->delete();

                ServiceBooking::where('booker_id', $id)->delete();
                ServiceMessages::where('to_id', $id)->orWhere('from_id', $id)->delete();

                if ($user->id_passport) {
                    $filePath = public_path($user->id_passport);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }

                if ($user->pin) {
                    $filePath = public_path($user->pin);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }

            }
            else if($type == 2){
                // Delete all Grant owner files & Grant profile
                $grant_pro = GrantProfile::where('user_id', $id)->first();
                if($grant_pro && $grant_pro->document){
                    $filePath = public_path($grant_pro->document);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
                $grant_pro->delete();

                $grants = Grant::where('user_id', $id)->get();
                foreach ($grants as $grant) {
                    $filePath = public_path($grant->grant_brief_pdf);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                    $grant->delete();
                }

                ServiceBooking::where('booker_id', $id)->delete();
                ServiceMessages::where('to_id', $id)->orWhere('from_id', $id)->delete();
            }
            else if($type == 3){
                // Delete all Capital owner files & Capital profile
                $cap = CapitalProfile::where('user_id', $id)->first();
                if($cap && $cap->document){
                    $filePath = public_path($cap->document);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
                $cap->delete();
                $capitals = CapitalOffer::where('user_id', $id)->get();
                foreach ($capitals as $capital) {
                    $filePath = public_path($capital->offer_brief_pdf);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                    $capital->delete();
                }

                ServiceBooking::where('booker_id', $id)->delete();
                ServiceMessages::where('to_id', $id)->orWhere('from_id', $id)->delete();
            }
            else if($type == 4 || $type == 5){
                //Delete Business owner documents
                ServiceBooking::where('booker_id', $id)->delete();
                ServiceMessages::where('to_id', $id)->orWhere('from_id', $id)->delete();
                Messages::where('to_id', $id)->orWhere('from_id', $id)->delete();

                $listings = Listing::where('user_id', $id)->get();
                $services = Services::where('user_id', $id)->get();
                foreach ($listings as $listing) {
                    $pin = public_path($listing->pin);
                    $identification = public_path($listing->identification);
                    $document = public_path($listing->document);
                    $video = public_path($listing->video);

                    if (file_exists($pin)) { unlink($pin); }
                    if (file_exists($video)) { unlink($video); }
                    if (file_exists($document)) { unlink($document); }
                    if (file_exists($identification)) { unlink($identification); }

                    $listing->delete();
                }
                BusinessBids::where('owner_id', $id)->delete();
                AcceptedBids::where('owner_id', $id)
                    ->whereNotIn('status', ['Confirmed', 'awaiting_payment', 'under_verification'])
                    ->delete();

                foreach ($services as $service) {
                    $pin = public_path($service->pin);
                    $identification = public_path($service->identification);
                    $document = public_path($service->document);
                    $video = public_path($service->video);

                    if (file_exists($pin)) { unlink($pin); }
                    if (file_exists($video)) { unlink($video); }
                    if (file_exists($document)) { unlink($document); }
                    if (file_exists($identification)) { unlink($identification); }

                    $service->delete();
                }
                ServiceBooking::where('service_owner_id', $id)->delete();
            }

            User::where('id',$id)->delete();
            DB::commit();
            return response(['message' => 'Account removed. All documents deleted.'],200);
        }
        catch (\Exception $e) {
            DB::rollBack();

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
                'acc_number' => 'required',
                'purpose' => 'required|string',
            ]);

            $existingSubscription = BusinessSubscriptions::where('investor_id', Auth::id() )
                ->where('plan', $validated['plan'])->where('expire_date', '>', now())->exists();

            if ($existingSubscription) {
                return response()->json(['message' => 'You already have an active subscription for this plan.'], 409);
            }

            $token = $this->lirpAuth->authorize();

            $rate = $converter->UsdToKes('USD');
            if (!$rate || $rate <= 0) {
                throw new \Exception("Currency conversion rate unavailable");
            }

            $platform_wallet = Setting::where('key', 'platform_lipr_wallet')->value('value');

            if (!$platform_wallet)
            {
                return response()->json(['message' => 'Tujitume platform lipr account not found.'], 404);
            }

            $amount = config("subscriptions.plans.{$validated['plan']}");

            // Trial logic

            // Getting payable USD amount, $channel = 'local_card', 'intl_card'
            $amountPayable = $this->checkoutCalculator->mpesa($amount, 'mpesa');
            if (!$amountPayable || $amountPayable <= 0) {
                throw new \Exception("Invalid payment amount calculated");
            }

            $amountKes = $amountPayable * $rate; // USD * KES_RATE

            // Check if user has balance in lipr wallet

            $lipr = $this->retriveBalance->lipr($user->id);
            $lipr['success'] = true;

            // Subscribe with wallet balance
            if ($lipr['success']) {
                $liprBalance = 1500; //$lipr['balance'];

                if ($liprBalance >= $amountKes) {
                    $tujitume_lipr_wallet = Setting::where('key', 'platform_lipr_wallet')->value('value');

                    // W2W transfer from user to platform wallet
                    $transferResult = $this->liprW2W->send(
                        $amountKes, $tujitume_lipr_wallet, $user->lipr_wallet, 'Subscription payment'
                    );

                    if(!$transferResult){
                        throw new \Exception('Tujitme lipr wallet does not exist.', 404);
                    }
                    if(!$transferResult['success']){
                        throw new \Exception($transferResult['errors'][0], 422);
                    }

                    //Transaction
                    $this->transaction->create(
                        $user->id,'subscription','lipr', $amountKes, $transferResult['id']
                    );

                    $this->wallet_subscrition_db_update($validated['plan'], $validated['trial'], $user, 'tranfer_id');

                    return response()->json([
                        'subscribe_via_wallet' => true,
                        'message' => 'Subscription payment successful via Lipr wallet balance.',
                        'data' => $transferResult,
                    ], 200);
                }
            }


            $base_path = config('services.lipr.base_path');

            $url = $base_path . "/partners/v1/payments/mobile-money/stk";

            $fields = [
                "requestId" => 'stk-' . now()->format('YmdHis') . '-' . uniqid(),
                "resultUrl" => "https://tujitume.com/api/lipr-callback",
                "timeoutUrl" => "https://tujitume.com/api/lipr-callback/timeout",
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
            curl_close($ch); // Always close the connection
            $result = json_decode($result, true);

            if (curl_errno($ch)) {
                $error = curl_error($ch);
                curl_close($ch);
                return response()->json(['message' => $error], 500);

            }

            return response()->json([
                'subscribe_via_wallet' => false,
                'data' => $result,
                'message' => 'Insufficient lipr balance or invalid wallet. Please proceed with mobile payment.',
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


    public function lipr_subscription(Request $request)
    {
        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!' ],401);
        }

        try {
            $request->validate([
                'plan' => 'required|string|in:silver,gold,platinum',
                'trial' => 'nullable|boolean', //null / 0 / 1
                'referenceId' => 'required|numeric',
            ]);
            $plan = $request->plan;
            $isTrial = $request->trial ?? false;
            $referenceId = $request->referenceId;

            $paymentExists = LiprPayment::where('reference_id', $referenceId)->first();
            if (!$paymentExists) {
                return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
            }

            if ($paymentExists->status === "failed") {
                return response()->json([
                    'status' => 'failed', 'updated_at' => now(),
                    'message' => 'Customer rejected payment or did not pay',
                ], 200);
            }

            $user = Auth::user();

            // Transaction Begins:
            DB::transaction(function () use ($referenceId, $paymentExists,$isTrial, $plan, $user) {
                $payment = LiprPayment::where('reference_id', $referenceId)->lockForUpdate()->first();

                if ($payment->status === 'completed') {
                    throw new \Exception('Payment already completed.', 409);
                }

                if ($payment->status !== 'processed') {
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
                    'investor_id' => $user->id,
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

            // return pre-transaction status (frontend expects 'processed')
            return response()->json([
                'status' => $paymentExists->status,
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
                    'investor_id' => $user->id,
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
