<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Finance\LiprPayment;
use App\Models\Programs\ProgramWallet;
use App\Service\Balance\BalanceService;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgramWalletController extends Controller
{
    public function __construct(){
        parent::__construct();
        $this->liprAuth = new LiprAuthService();
        $this->balance = new BalanceService();
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }


    /**
     * Display the specified resource.
     */
    public function show(int $id)
    {
        try{
            //$user_id = Auth::id();
            $wallet = ProgramWallet::with(['program'])->where('program_id', $id)->first();
            return response()->json(['wallet' => $wallet]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // Store wallet
    public function store(Request $request)
    {
        //
    }

    /**
     * Deposit funds to wallet (with proof)
     * POST /api/v1/program-wallets/{wallet}/deposit
     */
    //D E P O S I T  M E T H O D S
    public function deposit (Request $request, ProgramWallet $wallet)
    {
        if(!$wallet){
            return response()->json(['message' => 'Wallet not found.'], 404);
        }

        $user = Auth::user(); //User::find(1);
        if($wallet->program->user_id !== $user->id){
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        try {
            $request->validate([
                'amount' => 'required|numeric|min:10', //KES
                'acc_number' => 'required', // Lipr acc
                'purpose' => 'required',
            ]);

            $token = $this->liprAuth->authorize();
            $amount =  round($request->amount, 2);

            $acc_number = preg_replace('/\D/', '', $request->acc_number);

            $base_path = config('services.lipr.base_path');

            $url = $base_path . "/partners/v1/payments/mobile-money/stk";

            $fields = [
                "requestId" => 'stk-' . now()->format('YmdHis') . '-' . uniqid(),
                "resultUrl" => "https://tujitume.com/api/lipr-callback",
                "timeoutUrl" => "https://tujitume.com/api/lipr-callback/timeout",
                //"metadata" => ["user_id" => $user->id, "purpose" => $request->purpose, //"customerId" => "CUST-441"],

                "wallet" => $wallet->lipr_wallet,
                "narration" => $request->purpose,
                "recipients" => [
                    [
                        "amount" => $amount,
                        "account" => $acc_number
                    ]
                ]
            ];

            $getApiResponse = $this->PostApiHelper($fields, $url, $token);
            $response = json_decode($getApiResponse, true);

            if( $response && $response['success'] == false ){
                return response()->json(['message' => $response['errors'] ], $response['status_code']);
            }
            return response()->json(['data' => $response ?? 'Api Error.'], 200);

        }
        catch (ValidationException $ve) {
            return response()->json(['message' => $ve->getMessage()], 422);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    public function deposit_status(Request $request, ProgramWallet $wallet)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthorized!'], 401);
        }
        $user = Auth::user();
        $amountUsd = null;

        try {
            $request->validate([
                'reference_id' => 'required',
                //'payment_reference' => 'nullable'|'in:lipr,stripe',
            ]);

            $payment = LiprPayment::where('reference_id', $request->reference_id)
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

            DB::transaction(function () use ($request, $user, &$amountUsd, &$payment, $wallet)
            {

                if ($payment->status === 'completed') {
                    throw new \Exception('Payment already completed.', 409);
                }

                if ($payment->status !== 'processed') {
                    throw new \Exception('Payment not ready for crediting.', 422);
                }
                $amountKes = $payment->amount_usd; $amountUsd = $payment->amount;

                //D B Update $
                $wallet->total_deposited += $amountKes;
                $wallet->balance += $amountKes;
                $wallet->staus = 'active';
                $wallet->save();

                $payment->update([ 'status' => 'completed' ]);

                //Transaction
                $this->transaction->create(
                    $user->id,'deposit','lipr', $payment->amount, $request->reference_id
                );
            });

            //NotificationService
            $this->programNotification->send('wallet.deposited', [$wallet->program->user], [
                'amount' => $amountUsd, 'program_title' => $wallet->program->program_title,
                'new_balance' => $wallet->balance, 'program_id' => $wallet->program_id,
            ]);

            return response()->json([
                'status' => 'processed',
                'updated_at' => now(),
            ],200);
        }
        catch (ValidationException $ve) {
            return response()->json(['message' => $ve->getMessage()], 422);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Reserve funds for approved milestone
     * POST /api/v1/program-wallets/{wallet}/reserve
     */
    public function reserve(Request $request, ProgramWallet $wallet)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'milestone_id' => 'required|exists:program_milestones,id',
        ]);

        if ($validated['amount'] > $wallet->balance) {
            return response()->json([
                'error' => 'Insufficient balance. Available: ' . $wallet->balance
            ], 422);
        }

        $wallet->total_reserved += $validated['amount'];
        $wallet->balance -= $validated['amount'];
        $wallet->save();

        return response()->json([
            'message' => 'Funds reserved for milestone',
            'data' => $wallet,
        ]);
    }

    /**
     * Release reserved funds (if milestone rejected)
     * POST /api/v1/program-wallets/{wallet}/release-reserve
     */
    public function releaseReserve(Request $request, ProgramWallet $wallet)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        if ($validated['amount'] > $wallet->total_reserved) {
            return response()->json([
                'error' => 'Cannot release more than reserved amount: ' . $wallet->total_reserved
            ], 422);
        }

        $wallet->total_reserved -= $validated['amount'];
        $wallet->balance += $validated['amount'];
        $wallet->save();

        return response()->json([
            'message' => 'Reserved funds released back to balance',
            'data' => $wallet,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
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

    // HELPER METHODS
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
}
