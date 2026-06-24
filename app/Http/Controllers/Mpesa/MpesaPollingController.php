<?php

namespace App\Http\Controllers\Mpesa;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\Conversation;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalMilestone;
use App\Models\Capital\StartupPitches;
use App\Models\Finance\LiprPayment;
use App\Models\Grants\GrantMilestone;
use App\Models\Milestones\Milestones;
use App\Models\Misc\Setting;
use App\Models\Services\ServiceBook;
use App\Models\Services\ServiceBookingMilestone;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\LiprMpesa\GrantDisbursementService;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class MpesaPollingController extends Controller
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



    // P O L L I N G   P A Y M E N T  S T A T U S   &   U P D A T E  D A T A B A S E

    public function status_smallFee(Request $request)
    {
        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!' ],401);
        }
        $investor = Auth::user();

        try {
            $request->validate([
                'business_id' => 'required|numeric|exists:listings,id',
                'referenceId' => 'required',
            ]);
            $business_id = $request->business_id;
            $referenceId = $request->referenceId;

            $paymentExists = LiprPayment::where('reference_id', $referenceId)->first();
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
            DB::transaction(function () use ($referenceId, $business_id, $paymentExists, $investor) {
                $payment = LiprPayment::where('reference_id', $referenceId)->lockForUpdate()->first();
                $listing = Listing::findOrFail($business_id);

                if ($payment->status === 'completed') {
                    throw new \Exception('Payment already completed.', 409);
                }

                if (strtolower($payment->status) !== 'successful') {
                    throw new \Exception('Payment not ready for crediting.', 422);
                }

                // If Processed
                $unlockAmount = (float) $listing->investors_fee;
                $paidAmount = $payment->amount_usd;

                Conversation::create([
                    'investor_id' => $investor->id,
                    'listing_id' => $business_id,
                    'package' => null,
                    'price' => $unlockAmount
                ]);

                //L i p r  Transfer API from Tujitume to Sme wallet
                $transferAmount = round($unlockAmount,2);
                $amountKes = round($this->usdToKes * $transferAmount, 2);

                $transfer = $this->liprW2W->send(
                    $amountKes, $listing->owner->lipr_wallet, $this->tujitume_lipr, 'Unlocking business fee'
                );

                if(!$transfer){
                    throw new \Exception('Receiver or payer wallet does not found.', 404);
                }

                $transfer['success'] = true;

                if(!isset($transfer['success']) || !$transfer['success'] ){
                    throw new \Exception($transfer['message'], 422);
                }

                //Update User Wallet
                $this->balance->updateBalance($listing->user_id, $unlockAmount, 'lipr');

                $payment->update(['status' => 'completed']);

                //Transaction
                $this->transaction->create(
                    $listing->user_id,'unlock_business','lipr', $payment->amount, $referenceId, $investor->id
                );

                $this->transaction->create(
                    $investor->id,'unlock_business','lipr', $payment->amount, $referenceId, $listing->user_id
                );

            });

            // return pre-transaction status (frontend expects 'processed')
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


    public function status_bids(Request $request)
    {
        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!' ],401);
        }
        $investor = Auth::user();
        $investor_id = $investor->id;

        try {
            $request->validate([
                'business_id' => 'required|integer|min:1',
                'referenceId' => 'required',
                'amountUSD' => 'required|numeric|min:0',
                'amountPaid' => 'required|numeric|min:0',
                'share' => 'nullable|numeric|min:0'
            ]);
            $referenceId = $request->referenceId;
            $business_id = $request->business_id;
            $business = listing::with('owner')->where('id', $business_id)->first();

            if (!$business || !$business->owner) {
                return response()->json(['message' => 'Business or owner not found.'], 404);
            }
            $owner = $business->owner;

            $paymentExists = LiprPayment::where('reference_id', $referenceId)->first();
            if (!$paymentExists) {
                return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
            }

            if (strtolower($paymentExists->status) === "failed") {
                return response()->json([
                    'status' => 'failed', 'updated_at' => now(),
                    'message' => 'Customer rejected payment or did not pay',
                ], 200);
            }

            $amountFromDb = $paymentExists->amount;
            if ($request->amountPaid != $amountFromDb)
                return response()->json([
                    'error_in' => $request->amountPaid .'!='. $amountFromDb,
                    'message' => 'Amount does not match the original amount!'. $request->amountPaid .'!='. $amountFromDb,
                ], 400);

            DB::transaction(function() use($request, $investor, $owner, &$paymentExists, $business, $referenceId, $investor_id)
            {
                $payment = LiprPayment::where('reference_id', $referenceId)->lockForUpdate()->first();

                if ($payment->status === 'completed') {
                    throw new \Exception('Payment already completed.', 409);
                }

                if (strtolower($payment->status)  !== 'successful') { //SUCCESSFUL
                    throw new \Exception('Payment not ready for crediting.', 422);
                }


                $bids = BusinessBids::create([
                    'date' => date('Y-m-d'),
                    'investor_id' => $investor->id,
                    'business_id' => $business->id,
                    'owner_id' => $owner->id,
                    'type' => 'Monetary',
                    'method' => 'lipr',
                    'amount' => $request->amountUSD,
                    'representation' => $request->share,
                    'lipr_transaction_id' => $referenceId
                ]);


                $total_bid_amount = 0;
                $mile1 = Milestones::select('id', 'listing_id', 'amount')
                    ->where('listing_id', $business->id)->where('status', 'In Progress')->first();

                $total_bid_amount = $business->bids()->sum('amount') + $paymentExists->amount_usd;

                // Milestone Fulfill check
                if ($mile1){
                    if ($total_bid_amount >= $mile1->amount) {
                        $business->update(['threshold_met' => 1]);

                        $info = ['business_name' => $business->name];
                        $user['to'] = $business->owner->email;
                        Mail::send('bids.mile_fulfill', $info, function ($msg) use ($user) {
                            $msg->to($user['to']);
                            $msg->subject('Fulfills a milestone!');
                        });

                        //NotificationService
                        $text = 'A milestone for your business ' . $business->name . ' can now be fulfilled. You can start reviewing/accepting bids as well.';
                        $this->notification->create($business->owner->id, $investor_id, $text, 'investment-bids', 'investor');
                    }
                }
                // Milestone Fulfill check

                //$this->balance->updateBalance($user->id, $amountUsd, 'User wallet deposit.');
                $payment->update([ 'status' => 'completed' ]);

                //Mail & Notify
                $info=[
                    'business_name'=>$business->name,
                    'bid_id'=>$bids->id,
                    'type' => 'Monetary'
                ];
                $user['to'] = $investor->email;

                try {
                    Mail::send('bids.under_review', $info, function ($msg) use ($user) {
                        $msg->to($user['to']);
                        $msg->subject('Bid Under Review!');
                    });
                }
                catch (\Throwable $e) {
                    ErrorLogService::report($e, [
                        'input' => request()->except(['password', 'token']),
                    ]);
                    Log::warning("Mail sending failed for bid {$bids->id}: " . $e->getMessage());
                }

                $this->transaction->create(
                    $investor->id,'investment','lipr',
                    $payment->amount, $referenceId, $owner->id
                );
            });

            $text = 'You have a new bid from _name.';
            $this->notification->create($business->user_id,$investor_id,$text,'investment-bids','investor');

            //$paymentExists->refresh();
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


    public function status_bidsAwaiting(Request $request)
    {
        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!' ],401);
        }
        $investor = Auth::user();

        try{
            $request->validate([
                'business_id' => 'required|numeric|exists:accepted_bids,id',
                'referenceId' => 'required',
            ]);
            $bid_id = $request->business_id;
            $referenceId = $request->referenceId;

            $paymentExists = LiprPayment::where('reference_id', $referenceId)->first();
            if (!$paymentExists) {
                return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
            }

            if (strtolower($paymentExists->status) === "failed") {
                return response()->json([
                    'status' => 'failed', 'updated_at' => now(),
                    'message' => 'Customer rejected payment or did not pay',
                ], 200);
            }

            $bid = AcceptedBids::with('listing.owner')->where('id', $bid_id)->first();
            if (!$bid || $bid->investor_id !== $investor->id) {
                return response()->json(['message' => 'Unauthorized action.'], 403);
            }

            // Transaction Begins:
            DB::transaction(function () use ($referenceId, $bid, $paymentExists, $investor)
            {
                $payment = LiprPayment::where('reference_id', $referenceId)->lockForUpdate()->first();

                if ($payment->status === 'completed') {
                    throw new \Exception('Payment already completed.', 409);
                }

                if (strtolower($payment->status)  !== 'successful') {
                    throw new \Exception('Payment not ready for crediting.', 422);
                }

                // If Processed
                $paidAmount = $payment->amount_usd;
                $bid->update([
                    'status' => 'Confirmed',
                    'lipr_transaction_id' => $referenceId
                ]);
                $payment->update([ 'status' => 'completed' ]);

                //Transaction
                $this->transaction->create(
                    $investor->id,'investment_awaiting','lipr', $payment->amount, $referenceId, $bid->listing->owner->id
                );

            });

            //NotificationService
            $text = 'Your bid to business '.$bid->listing->name.' is confirmed.';
            $this->notification->create($investor->id,$bid->owner_id,$text,'/','business');

            //Email
            $info=[ 'business_name'=> $bid->listing->name, 'bid_id'=> base64_encode($bid->id), 'type' => $bid->type ];
            $user['to'] = $investor->email;
            try{
                Mail::send('bids.accepted' , $info, function($msg) use ($user){
                    $msg->to($user['to']);
                    $msg->subject('Bid Confirmed!');
                });
            } catch (\Throwable $e) {
                ErrorLogService::report($e, [
                    'input' => request()->except(['password', 'token']),
                ]);
                Log::warning("Mail sending failed for bid {$bid->id}: " . $e->getMessage());
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



    public function status_service(Request $request)
    {
        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!' ],401);
        }
        $customer = Auth::user();

        try {
            $request->validate([
                'business_id' => 'required|integer|exists:service_booking_milestones,id',
                'referenceId' => 'required',
            ]);

            //For Replica (ServiceMileStatus) table
            $rep_id = $request->business_id;
            $referenceId = $request->referenceId;
            $milestone = ServiceBookingMilestone::findOrFail($rep_id);
            $service = $milestone->service;

            if (!$milestone || !$service) {
                return response()->json(['message' => 'Milestone or Service not found.'], 404);
            }

            if ($milestone->booker_id !== $customer->id) {
                return response()->json(['message' => 'Unauthorized payment.'], 403);
            }


            $paymentExists = LiprPayment::where('reference_id', $referenceId)->first();
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
            $mailJobs = [];
            DB::transaction(function () use ($referenceId, $milestone, $service, $paymentExists, $customer, &$mailJobs) {
                $payment = LiprPayment::where('reference_id', $referenceId)->lockForUpdate()->first();

                if ($payment->status === 'completed') {
                    throw new \Exception('Payment already completed.', 409);
                }

                if (strtolower($payment->status)  !== 'successful') {
                    throw new \Exception('Payment not ready for crediting.', 422);
                }

                // If Processed
                $paidAmount = $payment->amount_usd;
                $milestone->update([ 'status' => 'In Progress']);
                $booking = serviceBook::where('id',$milestone->booking_id)->first();

                $booking->update([
                    'paid' => 1,
                    'method' => 'lipr',
                    'stage' => 'Payment Made'
                ]);


                $bid = AcceptedBids::find($booking->business_bid_id);
                // P r o j e c t  M a n a g e m e n t  - Service
                if ( $bid && ($service->category == '0' || $service->category == 'project_management'))
                {

                    if (!$bid->listing || !$bid->listing->owner || !$service->owner) {
                        throw new \Exception('Invalid data relations for project manager assignment.', 422);
                    }
                    if (!$bid->listing || !$bid->listing->owner) {
                        throw new \Exception('Bid or business owner for related bid not found.', 422);
                    }
                    if (!$service->owner) {
                        throw new \Exception('Service owner for related bid not found.', 422);
                    }

                    $business_owner = $bid->listing->owner;
                    $bid->update(['status' => 'manager_assigned', 'project_manager' => $service->owner->id ]);

                    try{
                        //M. Assigned Alert, B_Owner & S_Owner
                        $investor_name = $customer->fname. ' '.$customer->lname;
                        $manager = $service->owner->fname. ' '.$service->owner->lname;

                        // Store mail jobs to run after commit
                        $mailJobs[] = [
                            'view' => 'bids.owner_manager_alert',
                            'info' => ['mail_to'=>'owner','manager_name'=>$manager,'contact'=>$service->owner->email,'investor_name'=>$investor_name],
                            'to' => $business_owner->email,
                            'subject' => 'Project Manager Assigned!'
                        ];

                        $mailJobs[] = [
                            'view' => 'bids.owner_manager_alert',
                            'info' => ['mail_to'=>'manager','contact'=>$business_owner->email,'investor_name'=>$investor_name],
                            'to' => $service->owner->email,
                            'subject' => 'Project Manager Assigned!'
                        ];

                        //NotificationService B_Owner & S_Owner
                        $text = 'Project Manager '.$manager.' has been assigned to help verify the equipment from the investor '.$investor_name;
                        $this->notification->createWithBidId(
                            $business_owner->id, $service->owner->id, $bid->id, $text, 'verify_request_manager', 'business'
                        );

                        $text = 'You been assigned to help verify the equipment from the investor '.$investor_name;
                        $this->notification->createWithBidId(
                            $service->owner->id, $business_owner->id, $bid->id, $text, '/', 'business'
                        );
                    }
                    catch (\Throwable $e) {
                        ErrorLogService::report($e, [
                            'input' => request()->except(['password', 'token']),
                        ]);
                        Log::warning("Mail sending or NotificationService failed: " . $e->getMessage());
                    }

                }
                // P r o j e c t  M a n a g e m e n t  - Service

                //Transaction
                $this->transaction->create(
                    $customer->id,'service_fee','lipr', $payment->amount, $referenceId, $service->owner->id
                );

            });
            // Transaction Ends

            DB::afterCommit(function () use ($mailJobs, $service, $customer, $milestone) {

                foreach ($mailJobs as $job) {
                    try {
                        Mail::send($job['view'], $job['info'], function($msg) use ($job) {
                            $msg->to($job['to']);
                            $msg->subject($job['subject']);
                        });
                    } catch (\Throwable $e) {
                        ErrorLogService::report($e, [
                            'input' => request()->except(['password', 'token']),
                        ]);
                        Log::warning("Mail sending failed : " . $e->getMessage());
                    }
                }

                // General milestone mail
                // Alert Service Owner
                $info=[
                    'name'=>$milestone->title,
                    'amount'=>$service->price,
                    'business'=>$service->name,
                    's_id' => $service->id,
                    'customer'=>$customer->fname,
                    'id'=>$milestone->booking_id,
                    'note'=>$milestone->note
                ];

                try {
                    Mail::send('milestoneS.milestone_mail', $info, function($msg) use ($service, $customer) {
                        $msg->to([$service->owner->email, $customer->email]);
                        $msg->subject('Service Payment Received');
                    });
                } catch (\Throwable $e) {
                    ErrorLogService::report($e, [
                        'input' => request()->except(['password', 'token']),
                    ]);
                    Log::warning("Mail sending failed: " . $e->getMessage());
                }

            });

            //NotificationService
            $text = 'Service '.$service->name.' is paid, Milestone is ready to proceed.';
            $this->notification->create($service->owner->id, $customer->id, $text, '/', 'service');

            return response()->json([
                'status' => $paymentExists->status,
                'service_id' => base64_encode(base64_encode($service->id)),
                'updated_at' => now()
            ], 200);
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


    // G R A N T S  &  C A P I TA L

    public function grantDisbursementStatus(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthorized!'], 401);
        }

        try {
            $request->validate([
                'milestone_id' => 'required|numeric|exists:grant_milestones,id',
                'referenceId'  => 'required',
            ]);

            $payment = LiprPayment::where('reference_id', $request->referenceId)->first();

            // Leg 1 not arrived yet
            if (!$payment) {
                return response()->json([
                    'status' => 'pending',
                    'message'        => 'Processing your payment...',
                    'updated_at'     => now(),
                ], 200);
            }

            // Leg 1 failed
            if (strtolower($payment->status) === 'failed') {
                return response()->json([
                    'status' => 'failed',
                    'message'        => 'Payment failed or rejected.',
                    'updated_at'     => now(),
                ], 200);
            }

            $milestone = GrantMilestone::find($request->milestone_id);

            if (!$milestone->application->escrow_funded) {
                return response()->json(['status' => 'pending'], 200);
            }

            // Leg 2 completed
            if ($milestone->fund_release_status === 'released' && $milestone->fund_released) {
                return response()->json([
                    'status' => 'completed',
                    'message'        => 'Payment completed successfully.',
                    'updated_at'     => now(),
                ], 200);
            }

            // Leg 1 successful, Leg 2 processing
            if (strtolower($payment->status) === 'successful' || $milestone->fund_release_status === 'processing') {
                return response()->json([
                    'status'     => 'disbursing',  // ← clean lowercase
                    'message'    => 'Payment received. Transferring to supplier...',
                    'updated_at' => now(),
                ], 200);
            }

            // Fallback
            return response()->json([
                'status'     => 'pending',
                'message'    => 'Processing...',
                'updated_at' => now(),
            ], 200);


        }

        catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            if (in_array($e->getCode(), [404, 409, 422])) {
                return response()->json(['message' => $e->getMessage()], $e->getCode());
            }
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    public function capitalDisbursementStatus(Request $request)
    {
        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!' ],401);
        }
        $user = Auth::user();

        try {
            $request->validate([
                'milestone_id' => 'required|numeric|exists:capital_milestones,id',
                'referenceId' => 'required',
                //'percent' => 'numeric',
            ]);
            $milestone_id = $request->milestone_id;
            $referenceId = $request->referenceId;

            $paymentExists = LiprPayment::where('reference_id', $referenceId)->first();
            if (!$paymentExists) {
                return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
            }

            if (strtolower($paymentExists->status) === "failed") {
                return response()->json([
                    'status' => 'failed', 'updated_at' => now(),
                    'message' => 'Customer rejected payment or did not pay',
                ], 200);
            }


            $milestone = CapitalMilestone::where('id',$milestone_id)->first();
            $pitch = StartupPitches::with('capital_offer')->where('id',$milestone->app_id)->first();
            $emails = User::whereIn('id', [$pitch->user_id, $pitch->capital_owner_id])
                ->pluck('email', 'id');
            $sme_email = $emails[$pitch->user_id];
            $capital_owner_email = $emails[$pitch->capital_owner_id];

            if ($pitch->capital_owner_id !== $user->id) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $amountToTransfer = 0;
            // Transaction Begins:
            DB::transaction(function () use ($referenceId, $pitch, $paymentExists,$milestone_id, &$amountToTransfer) {
                $payment = LiprPayment::where('reference_id', $referenceId)->lockForUpdate()->first();
                $milestone = CapitalMilestone::where('id', $milestone_id)->lockForUpdate()->first();

                if ($payment->status === 'completed' || $milestone->status === 1) {
                    throw new \Exception('Payment already completed.', 409);
                }

                if (strtolower($payment->status)  !== 'successful') {
                    throw new \Exception('Payment not ready for crediting.', 422);
                }

                // If Processed
                $paidAmount = $payment->amount_usd;
                $milestoneAmount = $milestone->amount;

                //L i p r  Transfer API from Tujitume to Sme wallet
                $amountToTransfer = $this->checkoutCalculator->mpesaGC($milestoneAmount, 'mpesa');
                $amountKes = round($this->usdToKes * $amountToTransfer, 2);

                $transfer = $this->liprW2W->send(
                    $amountKes, $pitch->sme->lipr_wallet, $this->tujitume_lipr, 'Capital Milestone Disbursement'
                );

                if(!$transfer){
                    throw new \Exception('Tujitme lipr wallet does not exist.', 404);
                }
                if(!$transfer['success']){
                    throw new \Exception($transfer['errors'][0], 422);
                }
                //

                $milestone->update([
                    'status' => 1,
                    'fund_released' => 1,
                ]);
                $payment->update(['status' => 'completed']);
                $pitch->capital_offer->decrement('available_amount', $milestoneAmount);

                $this->transaction->create(
                    $pitch->capital_owner_id,'capital_milestone','lipr', $payment->amount, $referenceId, $pitch->user_id
                );

                $this->transaction->create(
                    $pitch->user_id,'capital_milestone','lipr', $payment->amount, $referenceId, $pitch->capital_owner_id
                );
            });

            //Update User Wallet
            $this->balance->updateBalance($pitch->user_id, $amountToTransfer, 'lipr');

            //N O T I F Y
            $text = $milestone->title.' fund for '.$pitch->capital_offer->offer_title.' has been released.';
            $this->notification->create($pitch->user_id,$pitch->capital_offer->user_id,$text
                ,'capitals-overview/capital/discover',' capital');

            // E M A I L
            $info=[
                'capital'=>$pitch->capital_offer->offer_title,
                'amount'=>$milestone->amount,
                'milestone_title' => $milestone->title
            ]; $user['to'] = [$capital_owner_email, $sme_email];

            Mail::send('opportunities.capital_milestone', $info, function($msg) use ($user){
                $msg->to($user['to']);
                $msg->subject(' Capital Milestone');
            });


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


}
