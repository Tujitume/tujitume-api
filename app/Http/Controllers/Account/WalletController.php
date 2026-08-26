<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Finance\LiprPayment;
use App\Models\Finance\StripePayments;
use App\Models\Misc\Setting;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\Balance\RetrieveBalanceService;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;

class WalletController extends Controller
{
    protected StripeClient $client;
    protected BalanceService $balance;
    protected LiprAuthService $liprAuth;
    protected RetrieveBalanceService $retriveBalance;
    public function __construct(StripeClient $client)
    {
        parent::__construct();

        $this->Client = $client;
        $this->balance = new BalanceService();
        $this->liprAuth = new LiprAuthService();
        $this->retriveBalance = new RetrieveBalanceService($this->Client);
    }

    public function conversion_rate($base)
    {
        try {
            $converter = new CurrencyConverter();
            if($base == 'USD') {
                $rate = $converter->UsdToKes();
            }
            else{
                $rate = $converter->KesToUsd();
            }
            $tujitume_fee = (float) Setting::where('key', 'tujitume_fee')->first()?->value ?? 3.0;
            $tujitume_fee = (float) ($tujitume_fee / 100);
            return response()->json([
                'rate' => $rate,
                'tujitume_fee' => $tujitume_fee
            ], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }
    public function create_wallet()
    {
        try {
            $user = Auth::user();

            $token = $this->liprAuth->authorize();
            $parentLiprWalletAccount = 'tujitume';

            $base_path = config('services.lipr.base_path');
            $url = $base_path . "/partners/v1/wallets";

            $fields = [
                "parentWalletAccount" => $parentLiprWalletAccount,
                "walletName" => $user->fname . ' ' . $user->lname,
                "walletDescription" => 'Tujitume customer wallet',
//                "metadata" => [
//                    //"department" => "ops",
//                    //"costCenter" => "Test"
//                ],
            ];

            $getApiResponse = $this->PostApiHelper($fields, $url, $token);
            $response = json_decode($getApiResponse, true);

            if (!isset($response['status'])) {
                return [
                    'success' => false,
                    'wallet' => null,
                    'message' => $response['error'] ?? 'Lipr API error',
                    'path' => $response['path'],
                ];
            }

            if ($response['status'] !== 200) {
                return [
                    'success' => false,
                    'wallet' => null,
                    'message' => $response['message'] ?? $response['error']
                            ?? $response['errors'] ?? 'API request failed',
                ];
            }

            return [
                'success' => true,
                'wallet' => $response['data'] ?? $response,
                'message' => 'Success',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false, 'wallet' => null,
                'message' => $e->getMessage(),
            ];
        }
    }

    // O N B o a r d i n g
    public function lipr_onboarding(Request $request)
    {
        try {
            $id = Auth::id();
            $user = User::select('lipr_wallet')->where('id', $id)->first();
            if($user->lipr_wallet != null){
                //return response()->json([ 'message' => 'Lipr wallet exists.'],409);
            }

            $createWallet = $this->create_wallet();

            if(isset($createWallet['success']) && $createWallet['success'] == false){
                return response()->json([
                    'message' => $createWallet['message'],
                    //'error' => $createWallet,
                ], 400);
            }
            elseif(isset($createWallet['success']) && $createWallet['success'] === true){
                User::where('id', $id)->update([ 'lipr_wallet' => $createWallet['wallet']['id'] ]);

                return response()->json([
                    'message' => 'Lipr wallet created.',
                    'wallet' => $createWallet
                ], 200);
            }
            else{
                return response()->json([
                    'message' => $createWallet['message'],
                ], 400);
            }

        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }

    }

    // stripe O n b o a r d i n g
    public function stripeOnboardingInitiate($id) {
        try{
            $seller = User::find($id);

            $role = null;

            if($seller->user_type_id == 4){
                $seller->load('organizationRole.role');
                $role = $seller->organizationRole?->role?->name ?? null;
            }
            else if($seller->user_type_id == 3){
                $seller->load('capital_profile.role');
                $role = $seller->capital_profile?->role?->name ?? null;
            }
            if($role){
                return response()->json(['message' =>  'Unauthorized, bad request.'], 400);
            }


            if(!$seller){
                return response()->json(['message' =>  'User not found.', 'status' => 404]);
            }

            if(!$seller->completed_onboarding) {
                $token = hexdec(uniqid());
                $seller->update([
                    'token'=>$token
                ]);

                $account = $this->Client->accounts->create([
                    'country' => 'us',
                    'type' => 'express',
                    'settings' => [
                        'payouts' => [
                            'schedule' => [
                                'interval' => 'manual',
                            ],
                        ],
                    ],
                ]);

                $account_id = $account['id'];
                $seller->update(['connect_id' => $account_id]);

                $account_links = $this->Client->accountLinks->create([
                    'account' => $account_id,
                    'refresh_url' => route('connect.stripe', ['id' => $id]),
                    'return_url' => route('return.stripe', ['token' => $token]),
                    'type' => 'account_onboarding',
                ]);

                redirect()->to($account_links->url)->send();
                //echo "<script>window.location.href='$account_links->url'</script>";
            }
            $login_link = $this->Client->accounts->createLoginLink($seller->connect_id);
            return redirect($login_link->url);
        }
        catch(\Exception $e){
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            DB::table('users')->where('id', Auth::user())
                ->update(['completed_onboarding'=>0]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.',
                'status' => 400
            ], 500);
        }
    }

    //Connect Callback
    public function onboardingSuccess($token) {
        $seller = User::where('token',$token)->first();
        if($seller){
            DB::table('users')->where('id',$seller->id)
                ->update(['completed_onboarding'=>1]);
        }
        redirect()->to(config('app.app_url').'dashboard')->send();
    }
    // O n b o a r d i n g   E N D S


    public function wallet_balances()
    {
        try
        {
            // Fetch User Wallets
            $user = Auth::user();
            $user_id = $user->id;

            //Role user check
            if($user->user_type_id == 4) {
                $user->load('organizationRole.role');
                $role = $user->organizationRole?->role?->name ?? null;
                if ($role) {
                    $user_id = $user->organizationOwnerId();
                }
            }
            else if($user->user_type_id == 3) {
                $user->load('capital_profile.role');
                $role = $user->capital_profile?->role?->name ?? null;
                if ($role) {
                    $user_id = $user->capital_profile?->capital_owner_id ?? $user->id;
                }
            }
            //

            $stripeBalance = $this->retriveBalance->stripe($user_id);

            $lipr = $this->retriveBalance->lipr($user_id);

            if (!$lipr['success']) {
                $liprBalance = 0; // or handle properly
            } else {
                $liprBalance = $lipr['balance'];
            }

            return response()->json([
                'stripe' => $stripeBalance !== false ? $stripeBalance : 'wallet not found',
                'lipr'   => $liprBalance,
                'error'  => (!$lipr['success']) ? $lipr['message'] : null
            ], 200);
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

    //D E P O S I T  M E T H O D S
    public function lipr_deposit (Request $request)
    {
        try {
            //$amountUsd = $convert->UsdToKes();

            $token = $this->liprAuth->authorize();
            $user = Auth::user(); //User::find(1);
            $request->validate([
                'amount' => 'required|numeric|min:10',//KES
                'acc_number' => 'required',
                'purpose' => 'required |string' ,
            ]);
            $amount =  round($request->amount, 2);

            $acc_number = preg_replace('/\D/', '', $request->acc_number);

            $base_path = config('services.lipr.base_path');
            $url = $base_path . "/partners/v1/payments/mobile-money/stk";

            $fields = [
                "requestId" => 'stk-' . now()->format('YmdHis') . '-' . uniqid(),
                "resultUrl" => "https://tujitume.com/api/lipr-callback",
                "timeoutUrl" => "https://tujitume.com/api/lipr-callback/timeout",
                //"metadata" => ["user_id" => $user->id, "purpose" => $request->purpose, //"customerId" => "CUST-441"],

                "wallet" => $user->lipr_wallet,
                "narration" => $request->purpose,
                "customerAccountNumber" => $acc_number,
                "recipients" => [
                    [
                        "amount" => $amount,
                        "account" => $acc_number
                    ]
                ]
            ];

            $getApiResponse = $this->PostApiHelper($fields, $url, $token);
            $response = json_decode($getApiResponse, true);

            if (!isset($response['success'])) {
                return response()->json([
                    'message' => $response['error'] ?? $response['message'] ?? 'Invalid response from lipr.',
                    'response' => $response
                ], 500);
            }

            if ($response['success'] !== true) {
                return response()->json([
                    'message' => $response['message']
                        ?? $response['error'] ?? $response['errors'] ?? 'API request failed',
                    'response' => $response
                ], $response['status_code'] ?? 400);
            }

            return response()->json(['data' => $response], 200);

        }
        catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }


    public function lipr_deposit_status(Request $request, NotificationService $notification)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthorized!'], 401);
        }
        $user = Auth::user();
        $amountUsd = null;

        try {
            $request->validate([
                'reference_id' => 'required',
                //'amount' => 'required|numeric',//KES
            ]);


                $payment = LiprPayment::where('reference_id', $request->reference_id)
                    //->where('user_id', $user->id)
                    ->lockForUpdate()->first();

                if (!$payment) {
                    return response()->json([ 'status' => 'pending', 'updated_at' => now() ],200);
                }

                if ($payment->status === "failed") {
                    return response()->json([
                        'status' => 'failed', 'updated_at' => now(),
                        'message' =>  'Customer rejected payment or did not pay',
                    ],200);
                }

            DB::transaction(function () use ($request, $user, &$amountUsd, &$payment)
            {

                if ($payment->status === 'completed') {
                    throw new \Exception('Payment already completed.', 409);
                }

                if ($payment->status !== 'processed') {
                    throw new \Exception('Payment not ready for crediting.', 422);
                }
                $amountUsd = $payment->amount_usd;
                //Balance Update $
                $this->balance->updateBalance($user->id, $amountUsd, 'lipr');
                $payment->update([ 'status' => 'completed' ]);

                //Transaction
                $this->transaction->create(
                    $user->id,'deposit','lipr', $payment->amount, $request->reference_id
                );
            });

            //NotificationService
            $link = 'account';
            if($user->user_type_id == 4 || $user->user_type_id == 3){
                $link = 'overview/account';
            }
            $text = 'Hi, your wallet was credited by USD $'. $amountUsd .' from deposit.';
            $notification->create($user->id, $user->id, $text, $link, 'deposit');
            //NotificationService

            return response()->json([
                'status' => 'processed',
                'updated_at' => now(),
            ],200);
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


    // S T R I P E   M E T H O D S, C A R D  D E P O S I T E
    public function stripe_deposit(Request $request, NotificationService $notification)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthorized!'], 401);
        }
        $user = Auth::user();
        $user->load('balance');

        try {
            $request->validate([
                'amount' => 'required|numeric',//USD
                'stripeToken' => ['required', 'string']
            ]);

            //Charge - Deposit
            $amount = round($request->amount, 2);
            $charge = $this->Client->charges->create ([
                //"billing_address_collection": null,
                "amount" => $amount*100, //100 * 100,
                "currency" => 'USD',
                "source" => $request->stripeToken,
                "description" => "Wallet deposit payment."
            ]);

            // L O G
            $payment = StripePayments::create([
                'user_id' => $user->id,
                'charge_id' => $charge->id,
                'amount' => $amount,
                'status' => 'charged',
            ]);

            if($charge && $charge->status == "succeeded")
            {
                $transaction = $this->Client->balanceTransactions->retrieve($charge->balance_transaction);
                $transfer = $this->Client->transfers->create([
                    "amount" => $transaction->net, //100 * 100,
                    "currency" => 'USD',
                    "source_transaction" => $charge->id,
                    'destination' => $user->connect_id
                ]);
                $payment->update(['status' => 'transferred']);

                //Balance Update $
                $netAmount = $transaction->net / 100;
                DB::transaction(function () use ($user, $netAmount, $charge) {
                    //Balance Update $
                    $this->balance->updateBalance($user->id, $netAmount, 'stripe');

                    //Transaction
                    $this->transaction->create(
                        $user->id,'deposit','stripe', $netAmount, $charge->id
                    );
                });
                //NotificationService
                $link = 'account';
                if($user->user_type_id == 4 || $user->user_type_id == 3){
                    $link = 'overview/account';
                }
                $text = 'Hi, your wallet was credited by USD $'. $netAmount .' from deposit.';
                $notification->create($user->id, $user->id, $text, $link, 'deposit');


                return response()->json([
                    'status' => $charge->status,
                    'message' => 'Deposit Success'
                ],200);
            }
            else{
                return response()->json([ 'message' => 'Deposit failed, try again' ],400);
            }


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


    public function PostApiHelper($fields, $url, $token)
    {
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
        //So that curl_exec returns the contents of the cURL; rather than echoing it
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
        $ApiResponse = curl_exec($ch);
        return $ApiResponse;
    }

// C l a s s  E n d s
}
