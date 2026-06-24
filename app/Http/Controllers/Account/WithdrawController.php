<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Finance\LiprPayment;
use App\Models\Finance\StripePayments;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\Balance\RetrieveBalanceService;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\LiprMpesa\LiprPaymentService;
use App\Service\LiprMpesa\WithdrawalService;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

class WithdrawController extends Controller
{
    protected StripeClient $client;
    protected BalanceService $balance;
    protected LiprAuthService $liprAuth;
    protected $transaction;
    protected RetrieveBalanceService $retriveBalance;

    private WithdrawalService  $withdrawalService;
    private LiprPaymentService $lipr;
    public function __construct(StripeClient $client)
    {
        parent::__construct();

        $this->Client = $client;
        $this->balance = new BalanceService();
        $this->liprAuth = new LiprAuthService();
        $this->retriveBalance = new RetrieveBalanceService($this->Client);

        $this->withdrawalService = new WithdrawalService();
        $this->lipr = new LiprPaymentService();

    }

    // Mobile  W I T H D R A W  M E T H O D S
    public function mobile_initiate_withdraw(Request $request)
    {
        try {
            $validated = $request->validate([
                'amount' => 'required|numeric',
                'phone'  => 'required',
            ]);

            $user      = Auth::user()->load('balance');
            $amountKes = round($validated['amount'], 2);

            $response = $this->withdrawalService->initiateToMobile(
                $user, $amountKes, $validated['phone']
            );

            if (!$this->lipr->isSuccess($response)) {
                return response()->json([
                    'message' => $this->lipr->getError($response)
                ], $this->lipr->getStatusCode($response));
            }

            return response()->json(['data' => $response], 200);

        } catch (\Exception $e) {

            $code = $e->getCode();

            if (in_array($code, [404, 422])) {
                return response()->json(['message' => $e->getMessage()], $code);
            }
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    public function mobile_withdraw_status(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthorized!'],401);
        }
        $user = Auth::user();
        $user->load('balance');
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
                return response()->json([
                    'status' => 'pending',
                    'updated_at' => now(),
                ],200);
            }

            if ($payment->status === "failed") {
                return response()->json([
                    'status' => 'failed',
                    'message' =>  'Customer rejected payment or did not pay',
                    'updated_at' => now(),
                ],200);
            }



                if ($payment->status === 'completed') {
                    throw new \Exception('Payment already completed.', 409);
                }

                if ($payment->status !== 'processed') {
                    throw new \Exception('Payment not ready for crediting.', 422);
                }

                $amountUsd = $payment->amount_usd;

                //Balance Update $
                $this->balance->updateBalanceMinus($user->id, $amountUsd, 'lipr-mobile');
                $payment->update([ 'status' => 'completed' ]);

                $this->transaction->create(
                    $user->id,'withdraw','lipr-mobile', $payment->amount, $request->reference_id
                );


            //NotificationService
            $link = 'account';
            if($user->user_type_id == 2 || $user->user_type_id == 3){
                $link = 'overview/account';
            }
            $text = 'Hi, your wallet was debited by USD $'. $amountUsd .' from withdraw.';
            $this->notification->create($user->id, $user->id, $text, $link, 'withdraw');

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


    // Paybill  W I T H D R A W  M E T H O D S
    public function paybill_initiate_withdraw(Request $request)
    {
        try {
            $validated = $request->validate([
                'amount'                 => 'required|numeric',
                'paybill_number'         => 'required',
                'paybill_account_number' => 'required',
            ]);

            $user      = Auth::user()->load('balance');
            $amountKes = round($validated['amount'], 2);

            $response = $this->withdrawalService->initiateToPaybill(
                $user,
                $amountKes,
                $validated['paybill_number'],
                $validated['paybill_account_number']
            );

            if (!$this->lipr->isSuccess($response)) {
                return response()->json([
                    'message' => $this->lipr->getError($response)
                ], $this->lipr->getStatusCode($response));
            }

            return response()->json(['data' => $response], 200);

        } catch (\Exception $e) {
            $code = $e->getCode();
            if (in_array($code, [404, 422])) {
                return response()->json(['message' => $e->getMessage()], $code);
            }
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function paybill_withdraw_status(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthorized!'],401);
        }
        $user = Auth::user();
        $user->load('balance');
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
                return response()->json([
                    'status' => 'pending',
                    'updated_at' => now(),
                ],200);
            }

            if ($payment->status === "failed") {
                return response()->json([
                    'status' => 'failed',
                    'message' =>  'Customer rejected payment or did not pay',
                    'updated_at' => now(),
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
                $this->balance->updateBalanceMinus($user->id, $amountUsd, 'lipr-paybill');
                $payment->update([ 'status' => 'completed' ]);

                $this->transaction->create(
                    $user->id,'withdraw','lipr-paybill', $payment->amount, $request->reference_id
                );
            });

            //NotificationService
            $link = 'account';
            if($user->user_type_id == 2 || $user->user_type_id == 3){
                $link = 'overview/account';
            }
            $text = 'Hi, your wallet was debited by USD $'. $amountUsd .' from withdraw.';
            $this->notification->create($user->id, $user->id, $text, $link, 'withdraw');

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


    // TILL  W I T H D R A W  M E T H O D S
    public function till_initiate_withdraw(Request $request)
    {
        try {
            $validated = $request->validate([
                'amount'      => 'required|numeric',
                'till_number' => 'required',
            ]);

            $user      = Auth::user()->load('balance');
            $amountKes = round($validated['amount'], 2);

            $response = $this->withdrawalService->initiateToTill(
                $user, $amountKes, $validated['till_number']
            );

            if (!$this->lipr->isSuccess($response)) {
                return response()->json([
                    'message' => $this->lipr->getError($response)
                ], $this->lipr->getStatusCode($response));
            }

            return response()->json(['data' => $response], 200);

        } catch (\Exception $e) {
            $code = $e->getCode();
            if (in_array($code, [404, 422])) {
                return response()->json(['message' => $e->getMessage()], $code);
            }
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function till_withdraw_status(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthorized!'],401);
        }
        $user = Auth::user();
        $user->load('balance');
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
                return response()->json([
                    'status' => 'pending',
                    'updated_at' => now(),
                ],200);
            }

            if ($payment->status === "failed") {
                return response()->json([
                    'status' => 'failed',
                    'message' =>  'Customer rejected payment or did not pay',
                    'updated_at' => now(),
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
                $this->balance->updateBalanceMinus($user->id, $amountUsd, 'lipr-till');
                $payment->update([ 'status' => 'completed' ]);

                $this->transaction->create(
                    $user->id,'withdraw','lipr-till', $payment->amount, $request->reference_id
                );
            });

            //NotificationService
            $link = 'account';
            if($user->user_type_id == 2 || $user->user_type_id == 3){
                $link = 'overview/account';
            }
            $text = 'Hi, your wallet was debited by USD $'. $amountUsd .' from withdraw.';
            $this->notification->create($user->id, $user->id, $text, $link, 'withdraw');

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


    // S T R I P E   M E T H O D S,  B A N K   W I T H D R A W
    public function stripe_withdraw(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthorized!'], 401);
        }
        $user = Auth::user();
        $user->load('balance');

        try {
            $request->validate([
                'amount' => 'required|numeric',//USD
            ]);
            $amount = round($request->amount, 2);

            // Fetch User Wallet
            $stripeBalance = $this->retriveBalance->stripe($user->id);

            //C R O S S  R A I L
            if( $amount > $stripeBalance || $amount > $user->balance?->balance ?? 0 )
            {
                return response()->json(['message' => $amount.' ($) Insufficient balance to complete request'],422);
            }

            // create payout from connected account
            $payout = $this->Client->payouts->create(
                [
                    'amount' => $amount*100,
                    'currency' => 'usd',
                ],
                ['stripe_account' => $user->connect_id]
            );

            if ($payout && $payout->id && $payout->status != 'failed')
            {
                $payout_amount = ($payout->amount)/100;
                $payout_id = $payout->id;

                //Balance Update $
                DB::transaction(function () use ($user, $payout_amount, $payout_id) {
                    // L O G
                    $payment = StripePayments::create([
                        'user_id' => $user->id,
                        'charge_id' => $payout_id,
                        'amount' => $payout_amount,
                        'status' => 'pending',
                    ]);
                    //Use webhook to mark it as paid

                    //Balance Update $
                    $this->balance->updateBalanceMinus($user->id, $payout_amount, 'stripe');

                    $this->transaction->create(
                        $user->id,'withdraw','stripe', $payout_amount, $payout_id, 'pending'
                    );
                });
                //NotificationService
                $link = 'account';
                if($user->user_type_id == 2 || $user->user_type_id == 3){
                    $link = 'overview/account';
                }
                $text = 'Hi, your wallet was debited by USD $'. $payout_amount .' from withdraw.';
                $this->notification->create($user->id, $user->id, $text, $link, 'withdraw');


                return response()->json([
                    'status' => $payout->status,
                    'message' => 'Withdraw initiated, funds are on the way!'
                ],200);
            }
            else{
                return response()->json(['status' => 'failed', 'message' => 'Withdraw failed by Stripe'],500);
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

//Class Ends
}
