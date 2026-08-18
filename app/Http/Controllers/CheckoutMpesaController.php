<?php

namespace App\Http\Controllers;

use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\Conversation;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalMilestone;
use App\Models\Capital\StartupPitches;
use App\Models\Finance\LiprPayment;
use App\Models\Grants\Disbursement;
use App\Models\Grants\GrantApplication;
use App\Models\Grants\GrantMilestone;
use App\Models\Milestones\Milestones;
use App\Models\Misc\Setting;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\LiprMpesa\GrantDisbursementService;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Session;

class CheckoutMpesaController extends Controller
{
    protected $public;
    protected $secret;
    protected $balance;
    protected $convert;
    protected $tujitume_lipr;
    protected LiprW2W $liprW2W;
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

    public function initiate_payment(Request $request, CurrencyConverter $converter)
    {
        try {

            if ($request->purpose === "grant_milestone_bulk") {
                $request->validate([
                    'amount' => 'required|numeric',
                    'amountKes' => 'numeric',
                    'acc_number' => 'required',
                    'purpose' => 'required|string',
                    'purpose_text' => 'required|string',
                    'listing_id' => 'required',
                ]);
            }
            else{
                $request->validate([
                    'amount' => 'required|numeric',
                    'acc_number' => 'required',
                    'purpose' => 'required|string',
                    'amountKes' => 'numeric|required_if:purpose,bids',
                    'purpose_text' => 'required|string',
                    'listing_id' => 'required|numeric',
                ]);
            }

            if(!Auth::check()){
                return response()->json(['message' => 'Unauthorized!'],401);
            }

            $token = $this->auth();
            $destination_wallet_acc = null;
            $platform_wallet = Setting::where('key', 'platform_lipr_wallet')->value('value');
            $tujitume_fee = (float) Setting::where('key', 'tujitume_fee')->first()->value;
            $rate = $converter->UsdToKes('USD');

            $callbackUrl = "https://tujitume.com/api/lipr-callback";

            if (!$platform_wallet)
            {
                return response()->json(['message' => 'Tujitume platform lipr account not found.'], 404);
            }

            // Get Payment type & amount from DB
            if ($request->purpose === "bids")
            {
                $amountKes = round($request->amountKes,0);
                //$destination_wallet_acc = $platform_wallet;
            }
            else if ($request->purpose === "small_fee")
            {
                $listing = Listing::select('id','investors_fee')->where('id', $request->listing_id)
                    ->with('owner') // eager load the owner
                    ->firstOrFail();
                $amount = $listing->investors_fee;
                //$destination_wallet_acc = $listing->owner->lipr_wallet;
            }
            else if ($request->purpose === "awaiting_payment")
            {
                $bid = AcceptedBids::select('id','business_id','amount')->where('id',$request->listing_id)
                    ->firstOrFail();
                $amount = round( ($bid->amount) * 0.75, 2); //75%
                //$destination_wallet_acc = $platform_wallet;
            }
            else if ($request->purpose === "grant_milestone")
            {
                $milestone = GrantMilestone::where('id',$request->listing_id)
                    ->with('application') // eager load the owner
                    ->firstOrFail();
                $amount = $milestone->amount;
                $callbackUrl = "https://tujitume.com/api/lipr-callback-grant-direct";

                // Authorization: only grant owner can disburse
                if ($milestone->application->grant_owner_id !== auth()->id()) {
                    throw new \Exception('Unauthorized', 403);
                }
            }
            else if ($request->purpose === "grant_milestone_escrow")
            {
                $milestone = GrantMilestone::where('id',$request->listing_id)
                    ->with('application') // eager load the owner
                    ->firstOrFail();
                $amount = $milestone->application->total_amount_requested ?? $milestone->application->awarded_amount;
                $callbackUrl = "https://tujitume.com/api/lipr-callback-grant-escrow";

                // Authorization: only grant owner can disburse
                if ($milestone->application->grant_owner_id !== auth()->id()) {
                    throw new \Exception('Unauthorized', 403);
                }
            }
            else if ($request->purpose === "grant_milestone_bulk")
            {
                $pitch_ids = $request->listing_id;
                $total_amount = GrantMilestone::whereIn('app_id', $pitch_ids)
                    ->orderBy('id')->get()->groupBy('app_id')
                    ->map(fn($group) => $group->first()->amount)
                    ->sum();
                $amount = $total_amount;
                //$destination_wallet_acc = $platform_wallet;
            }
            else if ($request->purpose === "capital_milestone")
            {
                $milestone = CapitalMilestone::where('id',$request->listing_id)
                    ->with('application') // eager load the owner
                    ->firstOrFail();
                $amount = $milestone->amount;
                //$destination_wallet_acc = $milestone->application->sme->lipr_wallet;
            }
            else if($request->purpose === 's_mile'){
                $mileR = ServiceBookingMilestone::with('service')->where('id',$request->listing_id)->first();
                $amount = $mileR->service->price;
                //$destination_wallet_acc = $platform_wallet;
            }

            if($request->purpose !== 'bids')
            {
                if($request->purpose === "grant_milestone_bulk"
                    || $request->purpose === "grant_milestone"
                    || $request->purpose === "capital_milestone"){
                    $amountKes = round($amount * $rate, 0); // USD * KES_RATE
                }
                else{
                    $amountPayable = $this->checkoutCalculator->mpesa($amount, 'mpesa');

                    // For local & intl card $channel = 'local_card', 'intl_card'
                    $amountKes = round($amountPayable * $rate, 0); // USD * KES_RATE
                }

            }


            $destination_wallet_acc = 'escrow'; //sandbox
             //$platform_wallet; prod // Money goes to platform first

            $base_path = config('services.lipr.base_path');

            $url = $base_path . "/partners/v1/payments/mobile-money/stk";

            $fields = [
                "requestId" => 'stk-' . now()->format('YmdHis') . '-' . uniqid(),
                "resultUrl" => $callbackUrl,
                //"resultUrl" => "http://127.0.0.1:8000/api/lipr-callback",
                "timeoutUrl" => "https://tujitume.com/api/lipr-callback/timeout",
                "metadata" => [ "listingId" => $request->listing_id ],

                "wallet" => $destination_wallet_acc,
                "narration" => $request->purpose,
                "recipients" => [
                    [
                        "amount" => $amountKes,
                        "account" => $request->acc_number
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
            $result = json_decode($result, true);

            return response()->json([
                'liprResponse' => $result,
                //'token' => $token, 'request' => $fields
                ], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        }
        catch (\Exception $e) {
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



    // SPECIAL BULK METHOD
    public function grant_milestone_bulk(Request $request)
    {
        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!' ],401);
        }
        $user = Auth::user();

        try {
            $request->validate([
                'pitch_ids' => 'required',
                'referenceId' => 'required',
                //'amounts_passed' => 'required',
            ]);

            $referenceId = $request->referenceId;
            $pitch_ids = array_filter(array_map('intval', $request->pitch_ids));
            if (empty($pitch_ids)) throw new \Exception('Invalid pitch IDs', 400);

            $paymentExists = LiprPayment::where('reference_id', $referenceId)->first();
            $paidAmount = $paymentExists->amount_usd;

            if (!$paymentExists) {
                return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
            }

            if (strtolower($paymentExists->status) === "failed") {
                return response()->json([
                    'status' => 'failed', 'updated_at' => now(),
                    'message' => 'Customer rejected payment or did not pay',
                ], 200);
            }

            // Transaction Begins:
            DB::transaction(function () use ($referenceId, $pitch_ids, $paymentExists, $user) {
                $payment = LiprPayment::where('reference_id', $referenceId)->lockForUpdate()->first();

                if ($payment->status === 'completed') {
                    throw new \Exception('Payment already completed.', 409);
                }

                if (strtolower($payment->status)  !== 'successful') {
                    throw new \Exception('Payment not ready for crediting.', 422);
                }

                // If Processed

                foreach($pitch_ids as $pitch_id)
                {
                    $pitch = GrantApplication::with('grant')->find($pitch_id);
                    $milestone = GrantMilestone::where('app_id',$pitch_id)->orderBy('id', 'asc')
                        ->lockForUpdate()->first();

                    if (!$pitch || !$milestone) continue;

                    if ($pitch->grant_owner_id !== $user->id) {
                        throw new \Exception('Unauthorized action.', 403);
                    }

                    if($milestone->status === 1)
                    {
                        continue;
                    }

                    $emails = User::whereIn('id', [$pitch->user_id, $pitch->grant_owner_id])
                        ->pluck('email', 'id');
                    $sme_email = $emails[$pitch->user_id];
                    $grant_owner_email = $emails[$pitch->grant_owner_id];


                    //L i p r  Release Transfer API from Tujitume to Sme wallet
                    $transferAmount = round($milestone->amount,2);

                    $amountToTransfer = $this->checkoutCalculator->mpesaGC($transferAmount, 'mpesa');
                    $amountKes = round($this->usdToKes * $amountToTransfer, 2);

                    $transfer = $this->liprW2W->send(
                        $amountKes, $pitch->sme->lipr_wallet, $this->tujitume_lipr, 'Grant Bulk Milestone Disbursement'
                    );

                    if(!$transfer){
                        throw new \Exception('Tujitme lipr wallet does not exist.', 404);
                    }
                    if(!$transfer['success']){
                        throw new \Exception($transfer['errors'][0], 422);
                    }

                    //U p d a t e
                    $milestone->update([
                        'status' => 1,
                        'fund_released' => 1,
                    ]);
                    $pitch->grant->decrement('available_amount', $milestone->amount);

                    //Update User Wallet
                    $this->balance->updateBalance($pitch->user_id, $amountToTransfer, 'lipr');


                    // E M A I L

                    try{
                        //N O T I
                        $text = $milestone->title.' fund for '.$pitch->grant->grant_title.' has been released.';
                        $this->notification->create($pitch->user_id,$pitch->grant->user_id,$text
                            ,'grants-overview/grants/discover',' grant');

                        $info=[
                            'grant'=>$pitch->grant->grant_title,
                            'amount'=>$milestone->amount,
                            'milestone_title' => $milestone->title
                        ]; $recipients['to'] = [$grant_owner_email, $sme_email];

                        Mail::send('opportunities.grant_milestone', $info, function($msg) use ($recipients){
                            $msg->to($recipients['to']);
                            $msg->subject(' Grant Milestone');
                        });
                    }
                    catch (\Throwable $e) {
                        ErrorLogService::report($e, [
                            'input' => request()->except(['password', 'token']),
                        ]);
                        Log::warning("Mail sending or NotificationService failed: " . $e->getMessage());
                    }

                }
                $payment->update(['status' => 'completed']);

            });

            try{

                $this->transaction->create(
                    $user->id,'grant_milestone_bulk','lipr', $paymentExists->amount, $referenceId
                );
            }
            catch (\Exception $e){
                ErrorLogService::report($e, [
                    'input' => request()->except(['password', 'token']),
                ]);
                Log::info('Transaction Create Error: '. $e->getMessage());
            }

            return response()->json([
                'status' => $paymentExists->status,
                'updated_at' => now(),
            ],200);
        }
        catch (\Exception $e) {
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


    // H E L P E R S


//Class Ends
}
