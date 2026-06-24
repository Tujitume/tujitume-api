<?php

namespace App\Http\Controllers;

use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\Conversation;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalMilestone;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\StartupPitches;
use App\Models\Finance\StripePayments;
use App\Models\Finance\Transactions;
use App\Models\Grants\Grant;
use App\Models\Grants\GrantApplication;
use App\Models\Grants\GrantMilestone;
use App\Models\Milestones\Milestones;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\ServiceMessages;
use App\Service\Balance\BalanceService;
use App\Service\Misc\ErrorLogService;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Session;
use Stripe\StripeClient;
use Stripe\Webhook;


class CheckoutStripeController extends Controller
{
    public function __construct(StripeClient $client)
    {
        parent::__construct();

        $this->Client = $client;
        $this->balance = new BalanceService();
    }

    public function callback(Request $request, BalanceService $balance)
    {
        //Log::info('Stripe Callback Received:', $request->all());

        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
            $payout = $event->data->object;

            switch ($event->type) {
                case 'payout.paid':
                    StripePayments::where('charge_id', $payout->id)->update([ 'status' => 'paid' ]);
                    Transactions::where('reference_id', $payout->id)->update([ 'status' => 'settled' ]);
                    break;

                case 'payout.failed':
                    $message = $payout->failure_message;
                    $amount = ($payout->amount)/100;
                    Transactions::where('reference_id', $payout->id)->update([ 'status' => 'failed' ]);
                    $payment = StripePayments::where('charge_id', $payout->id)->first();
                    if ($payment) {
                        $payment->update(['status' => 'failed']);
                        // Reverse User Balance
                        $balance->updateBalance($payment->user_id, $amount, 'stripe');

                        $text = 'Your latest withdraw via Stripe was failed and you were credited the amount back. Reason: '.$message;
                        $this->notification->create($payment->user_id, null, $text,'account', 'withdraw');
                    }
                    break;

                default:
                    Log::info('Received unknown event type: ' . $event->type);
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            if (in_array($e->getCode(), [404, 422])) {
                return response()->json(['message' => $e->getMessage()], $e->getCode());
            }

            Log::error('Stripe Webhook error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong, please try again later.'], 500);
        }

    }


    public function UnlockListing(Request $request)
    {
        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!' ],401);
        }
        $user = Auth::user();
        try {
            $request->validate([
                'listing' => 'required|integer|exists:listings,id',
                'stripeToken'  => 'required|string',
            ]);

            $listing = Listing::findOrFail($request->listing);
            $owner = $listing->owner;

            if ($user->id === $listing->user_id) {
                return response()->json(['message' => 'You cannot unlock your own listing'], 403);
            }

            $amount= (float) $listing->investors_fee;
            $amountPayable = $this->checkoutCalculator->stripe($amount);
            try{
                $charge = $this->Client->charges->create ([
                    //"billing_address_collection": null,
                    "amount" => (int) round($amountPayable * 100), //100 * 100,
                    "currency" => 'USD',
                    "source" => $request->stripeToken,
                    "description" => "Business unlock fee."
                ]);
            }
            catch (\Stripe\Exception\ApiErrorException $e) {
                ErrorLogService::report($e, [
                    'input' => request()->except(['password', 'token']),
                ]);

                if (in_array($e->getCode(), [404, 422])) {
                    return response()->json(['message' => $e->getMessage()], $e->getCode());
                }

                Log::error('Stripe charge failed', ['error' => $e->getMessage()]);
                return response()->json(['message' => 'Payment could not be processed by Stripe'], 500);
            }

            DB::beginTransaction();

            // L O G
            StripePayments::create([
                'user_id' => $user->id,
                'charge_id' => $charge->id,
                'amount' => $amountPayable,
                'status' => 'pending_transfer',
            ]);

            //Transaction
            $this->transaction->create(
                $user->id,'unlock_fee','stripe', $amountPayable, $charge->id
            );

            Conversation::firstOrCreate([
                'investor_id' => $user->id,
                'listing_id' => $listing->id,
            ], ['price' => $amount]);


            DB::commit();

             //Split
             DB::beginTransaction();

             if($charge->id && $owner->connect_id && $owner->completed_onboarding)
             {
                 try {
                     $this->Client->transfers->create([
                         'amount'             => $amount * 100,
                         'currency'           => 'USD',
                         'source_transaction' => $charge->id,
                         'destination'        => $owner->connect_id,
                     ]);
                 } catch (\Stripe\Exception\ApiErrorException $e) {
                     ErrorLogService::report($e, [
                         'input' => request()->except(['password', 'token']),
                     ]);

                     Log::error('Stripe transfer failed', ['error' => $e->getMessage()]);
                     // don't fail whole flow — just log it / Admin Alert / Queue for retry
                 }
             }
             // D B
            $this->balance->updateBalance($owner->id, $amount, 'stripe');

             DB::commit();
             return response()->json(['status' => 200, 'message' => 'Payment Successful, redirecting...'], 200);

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


    // S e r v i c e  P a y m e n t s
    public function PayServiceFee(Request $request)
    {
        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!' ],401);
        }
        $user = Auth::user();
        try {
            $request->validate([
                'milestone_id' => 'required|integer|exists:service_booking_milestones,id',
                'stripeToken'  => 'required|string',
            ]);

            $repMilestone = ServiceBookingMilestone::findOrFail($request->milestone_id);
            $service = $repMilestone->service;
            $owner = $service->owner;

            if (!$service || !$owner) {
                return response()->json(['message' => 'Service or owner not found'], 500);
            }

            if ($user->id === $service->user_id) {
                return response()->json(['message' => 'You cannot pay your own service fee'], 403);
            }

            if (!$repMilestone->booking) {
                return response()->json(['message' => 'Booking not found'], 500);
            }

            if ($repMilestone->booking->paid) {
                return response()->json(['message'=>'Milestone already paid'], 409);
            }

            $amount= (float) $service->price;
            $amountPayable = $this->checkoutCalculator->stripe($amount);
            try{
                $charge = $this->Client->charges->create ([
                    //"billing_address_collection": null,
                    "amount" => (int) round($amountPayable * 100), //100 * 100,
                    "currency" => 'USD',
                    "source" => $request->stripeToken,
                    "description" => "This is the service fee payment."
                ]);
            }
            catch (\Stripe\Exception\ApiErrorException $e) {
                ErrorLogService::report($e, [
                    'input' => request()->except(['password', 'token']),
                ]);

                Log::error('Charge failed', ['error' => $e->getMessage()]);
                return response()->json(['message' => 'Payment could not be processed by Stripe'], 400);
            }

            $repMilestone->booking()->update([
                'paid' => 1,
                'method' => 'stripe',
                'status' => 'paid',
                'stage' => 'paid'
            ]);
            //$repMilestone->update([ 'status' => 'In Progress']);
            //Transaction
            $this->transaction->create(
                $user->id,'service_fee','stripe', $amountPayable, $charge->id, $service->owner->id
            );

            StripePayments::create([ // L O G
                'user_id' => $user->id,
                'charge_id' => $charge->id,
                'amount' => $amountPayable,
                'status' => 'settled',
            ]);

            $text = 'Service '.$service->name.' is paid, milestone is ready to proceed.';
            $this->notification->create(
                $owner->id, $user->id, $text, '/', 'service'
            );

            $booking = $repMilestone->booking;

            // Asset/Project - Management - Service
            if($service->category == '0' || $service->category == 'project_management') {
                try{
                    $accepted_bid = AcceptedBids::select('business_id','owner_id','id')
                        ->where('id',$booking->business_bid_id)->first();

                    if (!$accepted_bid) {
                        return response()->json(['message' => 'Business bid not found'], 500);
                    }
                    $accepted_bid->update(['status' => 'manager_assigned', 'project_manager'=>$owner->id ]);
                    $b_owner = User::select('id','email')->where('id',$accepted_bid->owner_id)->first();

                    $investor_name = $user->fname. ' '.$user->lname;
                    $manager = $owner->fname. ' '.$owner->lname; // Manager == Service Owner

                    // N o t i f i c a t i o n
                    $text = 'Project Manager '.$manager.' has been assigned to help verify the equipment from the investor '.$investor_name;
                    $text2 = 'You been assigned to help verify the equipment from the investor '.$investor_name;

                    $this->notification->createWithBidId(
                        $b_owner->id, $owner->id, $accepted_bid->id, $text,
                        'verify_request_manager', 'business'
                    );

                    $this->notification->createWithBidId(
                        $owner->id, $b_owner->id, $accepted_bid->id,
                        $text2, '/', 'business'
                    );


                    //M. Assigned Alert, B_Owner
                    $info=['mail_to'=>'owner','manager_name'=>$manager, 'contact'=>$owner->email, 'investor_name' => $investor_name];
                    $mail1 = Mail::send('bids.owner_manager_alert', $info, function($msg) use ($b_owner){
                        $msg->to([$b_owner->email]);
                        $msg->subject('Project Manger Assigned!');
                    });

                    //M. Assigned Alert, S_Owner
                    $info=['mail_to'=>'manager', 'contact'=>$b_owner->email, 'investor_name' => $investor_name];
                    $mail1 = Mail::send('bids.owner_manager_alert', $info, function($msg) use ($owner){
                        $msg->to([$owner->email]);
                        $msg->subject('Project Manger Assigned!');
                    });
                }
                catch (\Exception $e){
                    ErrorLogService::report($e, [
                        'input' => request()->except(['password', 'token']),
                    ]);

                    Log::info('Asset-Management-Logic: '. $e->getMessage());
                }
            }
            //Asset/Project - Management Ends

            $infoOwner=[
                'amount'=>$service->price, 'business'=>$service->name,
                'note' => 'A message has also been sent to the client from you.',
                'customer'=>$user->fname, 'id'=>$booking->id
            ];
            $infoCustomer=[
                'amount'=>$service->price, 'business'=>$service->name,
                'note' => 'A message has also been sent to your dashboard inbox from the Service Provider.',
                'customer'=>$user->fname, 'id'=>$booking->id
            ];


             try{
                 Mail::send('milestoneS.milestone_mail', $infoOwner, function($msg) use ($owner){
                 $msg->to([$owner->email]);
                 $msg->subject('Service Payment Received');
                });

                 Mail::send('milestoneS.milestone_mail', $infoCustomer, function($msg) use ($user){
                     $msg->to([$user->email]);
                     $msg->subject('Service Payment Received');
                 });

                // M E S S A G E
                ServiceMessages::create([
                    'booker_id' => $user->id,
                    'service_id' => $service->id,
                    'service_owner_id' => $owner->id,
                    'msg' => 'Hi ' . $user->fname. ', my name is ' . $owner->fname .'. Thank you for booking my service, ' .$service->name. '. I\'m excited to work with you and will be in touch shortly with the next steps.',
                    'to_id' => $user->id,
                    'from_id' => $owner->id
                ]);
            } catch (\Exception $e) {
                 ErrorLogService::report($e, [
                     'input' => request()->except(['password', 'token']),
                 ]);

                Log::error('Mail send failed', ['error'=>$e->getMessage()]);
            }

            return response()->json([
                'message' =>  'Success',
                'service_id' => base64_encode(base64_encode($service->id)),
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

    // B I D S
    public function bidCommitPayment(Request $request)
    {
        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!' ],401);
        }
        $user = Auth::user();
        try {
            $request->validate([
                'listing' => 'required|integer|exists:listings,id',
                'amountOriginal' => 'required|numeric',
                'percent' => 'required|numeric',
                'stripeToken'  => 'required|string',
            ]);

            $listing = Listing::findOrFail($request->listing);
            $milestones = $listing->milestones()->orderBy('id')->get(); $mileActive = null;

            // Active incomplete milestone
            foreach ($milestones as $index => $milestone) {
                if ($milestone->progress_percentage < 100) {
                    // First incomplete milestone found
                    $mileActive = $milestone;
                    break;
                }
            }

            if (!$mileActive) {
                return response()->json(['message' => 'No active milestone found.'],400);
            }
            // Active Milestone Check Ends

            $owner = $listing->owner;

            if($listing->amount_collected >= $listing->investment_needed){
                return response()->json(['message' => 'Business is fully invested or all milestones completed..'], 400);
            }

            if ($user->id === $listing->user_id) {
                return response()->json(['message' => 'You cannot invest in your own listing'], 403);
            }

            $originalBidAmount= round($request->amountOriginal, 2);
            $partialAmount = $originalBidAmount * 0.25; // 25%
            //$amountPayable = round($partialAmount + ( $partialAmount* ($this->tujitume_fee/100) ),2);
            $amountPayable = $this->checkoutCalculator->stripe($partialAmount);

            try{
                $charge = $this->Client->charges->create ([
                    //"billing_address_collection": null,
                    "amount" => (int) round($amountPayable * 100), //100 * 100,
                    "currency" => 'USD',
                    "source" => $request->stripeToken,
                    "description" => "This is an investment payment."
                ]);
                Log::info('Stripe charge created, charge_id: '. $charge->id);
            }
            catch (\Stripe\Exception\ApiErrorException $e) {
                ErrorLogService::report($e, [
                    'input' => request()->except(['password', 'token']),
                ]);

                Log::error('Stripe charge failed', ['error' => $e->getMessage()]);
                return response()->json(['message' => 'Payment could not be processed by Stripe'], 400);
            }

            $ms_id = $mileActive->id;

            $bid = BusinessBids::create([
                'date' => date('Y-m-d'),
                'investor_id' => $user->id,
                'ms_id' => $ms_id,
                'business_id' => $listing->id,
                'owner_id' => $owner->id,
                'type' => 'Monetary',
                'method' => 'stripe',
                'amount' => $originalBidAmount,
                'representation' => $request->percent,
                'stripe_charge_id' => $charge->id
            ]);

            $mileActive->increment('pending_collected', $originalBidAmount);

            DB::beginTransaction();
            try {
                // L O G
                StripePayments::create([
                    'user_id' => $user->id,
                    'charge_id' => $charge->id,
                    'amount' => $amountPayable,
                    'status' => 'settled',
                ]);

                //Transaction
                $this->transaction->create(
                    $user->id,'investment','stripe', $amountPayable, $charge->id
                );

                DB::commit();
            }
            catch (\Exception $e) {
                DB::rollBack();
                ErrorLogService::report($e, [
                    'input' => request()->except(['password', 'token']),
                ]);

                Log::error('DB write failed after Stripe payment', ['error'=>$e->getMessage()]);
                return response()->json(['message'=>'Payment succeeded, but data could not be saved. Contact support.'], 500);
            }

            //NotificationService
            $text = 'You have a new bid from '.$user->fname.' '.$user->lname;
            $this->notification->create(
                $listing->user_id, $user->id, $text, 'investment-bids', 'investor'
            );

            $total_bid_amount = $listing->bids()->sum('amount')
                + $listing->accepted_bids()->sum('amount')
                + $originalBidAmount; // Pending + Accepted + Current

            // M i l e s t o n e   F u l f i l l   C h e c k
            if($total_bid_amount >= $mileActive->amount && !$listing->threshold_met)
            {
                $listing->update(['threshold_met' => 1]);
                $info=[ 'business_name'=>$listing->name ];

                try{
                    Mail::send('bids.mile_fulfill', $info, function($msg) use ($owner){
                        $msg->to([$owner->email]);
                        $msg->subject('Fulfills a milestone.');
                    });
                }
                catch (\Exception $e){
                    ErrorLogService::report($e, [
                        'input' => request()->except(['password', 'token']),
                    ]);

                    Log::error('Mail to Owner send failed', ['error'=>$e->getMessage()]);
                }

                // NotificationService
                $text = 'A milestone for your business '.$listing->name.' can now be fulfilled. You can start reviewing/accepting bids as well.';
                $this->notification->create(
                    $owner->id, $user->id, $text, 'investment-bids', 'investor'
                );
            }

            // M a i l
            $info=[ 'business_name'=>$listing->name, 'bid_id'=>$bid->id, 'type' => 'Monetary' ];
            try{
                Mail::send('bids.under_review', $info, function($msg) use ($user){
                $msg->to([$user->email]);
                $msg->subject('Bid Under Review!');
                });
            }
            catch (\Exception $e){
                ErrorLogService::report($e, [
                    'input' => request()->except(['password', 'token']),
                ]);

                Log::error('Mail to user send failed', ['error'=>$e->getMessage()]);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Bid placed! you will get a notification if your bid is accepted, go to dashboard to view investments?'
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





    public function bidAwaitingPayment(Request $request){
        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!' ],401);
        }
        $user = Auth::user();
        try {
            $request->validate([
                'bid_id' => 'required|integer|exists:accepted_bids,id',
                'stripeToken'  => 'required|string',
            ]);

            $bid = AcceptedBids::findOrFail($request->bid_id);

            //Multi-split bid check
            $original_bid_id = $bid->bid_id; // BusinessBids table
            $multi_bids = AcceptedBids::where('bid_id',$original_bid_id)->get();
            $total_bid_amount = $multi_bids->sum('amount');
            $bids_count = $multi_bids->count();

            $listing = $bid->listing;
            $owner = $listing->owner;

            if ($bid->investor_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized: This bid is not assigned to you.'], 403);
            }

            if ($user->id === $listing->user_id) {
                return response()->json(['message' => 'You cannot invest in your own listing'], 403);
            }

            if ($bid->status === 'confirmed') {
                return response()->json(['message' => 'This bid is already confirmed.'], 400);
            }

            if (!$listing || !$listing->owner) {
                return response()->json(['message' => 'Listing or owner not found'], 404);
            }

            $bidAmount = $bids_count > 1 ? ($total_bid_amount * 0.75) : ($bid->amount * 0.75); // 75%
            //$amountPayable = round($bidAmount + ( $bidAmount * ($this->tujitume_fee/100) ),2);
            $amountPayable = $this->checkoutCalculator->stripe($bidAmount);

            try{
                $charge = $this->Client->charges->create ([
                    //"billing_address_collection": null,
                    "amount" => (int) round($amountPayable * 100), //100 * 100,
                    "currency" => 'USD',
                    "source" => $request->stripeToken,
                    "description" => "This is an investment remaining payment."
//                ], [
//                    'idempotency_key' => 'bid_' . $bid->id . '_user_' . $user->id,
                ]);
                Log::info('Stripe charge created, charge_id: '. $charge->id);
            }
            catch (\Stripe\Exception\ApiErrorException $e) {
                ErrorLogService::report($e, [
                    'input' => request()->except(['password', 'token']),
                ]);

                Log::error('Stripe charge failed', ['error' => $e->getMessage()]);
                return response()->json(['message' => 'Payment could not be processed by Stripe'], 400);
            }

            $stripe_charge_id = $bid->stripe_charge_id ? $bid->stripe_charge_id.','.$charge->id : $charge->id;

            if($bids_count > 1){
                AcceptedBids::where('bid_id', $original_bid_id)->update([
                    'status' => 'confirmed',
                    'paid_in_full' => true,
                    'stripe_charge_id' => $stripe_charge_id
                ]);
            }
            else{
                $bid->update([
                    'status' => 'confirmed',
                    'paid_in_full' => true,
                    'stripe_charge_id' => $stripe_charge_id
                ]);
            }


            // Transactions & Stripe Log
            try {
                $this->transaction->create(
                    $user->id,'investment_awaiting','stripe', $amountPayable, $charge->id
                );

                StripePayments::create([
                    'user_id' => $user->id,
                    'charge_id' => $charge->id,
                    'amount' => $amountPayable,
                    'status' => 'settled',
                ]);
            } catch (\Exception $e) {
                ErrorLogService::report($e, [
                    'input' => request()->except(['password', 'token']),
                ]);

                Log::error('Transaction or StripePayments DB insert failed', [
                    'charge_id' => $charge->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $info=[
                'business_name'=>$listing->name, 'bid_id'=> base64_encode($bid->id), 'type' => $bid->type
            ];
            $data = [
                'investorName' => $bid->investor->fname,
                'milestoneName' => $bid->milestone->title,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];

            try{
                //Email & NotificationService

                // Pre release notify
                $text = 'Milestone '. $bid->milestone->title. ' is required Pre-release verification, please submit requirements.';
                $this->notification->create(
                    $bid->investor->id, null, $text, 'milestones', 'milestone'
                );
                // E m a i l
                $subject = ' Pre-release verification required';
                $mail_to = $bid->investor->email;

                $this->emailService->send($subject, 'milestone.pre_release.mprv_required', $data, $mail_to);
                // Pre release notify ENDS


                $text = 'Your bid to business '.$listing->name.' is confirmed!';
                $this->notification->create(
                    $user->id, $listing->user_id, $text, '/', 'business'
                );

                Mail::send('bids.accepted' , $info, function($msg) use ($user){
                    $msg->to([$user->email]);
                    $msg->subject('Bid Confirmed!');
                });
            }
            catch (\Exception $e){
                ErrorLogService::report($e, [
                    'input' => request()->except(['password', 'token']),
                ]);

                Log::error('Mail/NotificationService failed', [
                    'bid_id' => $bid->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json(['message' => 'Bid Confirmed! Please goto dashboard or check email', 'status' => 200], 200);
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


    public function rmepFundsCommit(Request $request)
    {
        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!' ],401);
        }
        $user = Auth::user();
        try {
            $request->validate([
                'listing' => 'required|integer|exists:listings,id',
                'percent' => 'nullable|numeric',
                'stripeToken'  => 'required|string',
            ]);

            $milestone_id = $request->listing;; // For RMEP, listing param is milestone id
            $milestone = Milestones::find($milestone_id);

            if(!$milestone){
                return response()->json(['message' => 'Milestone not found.'],400);
            }

            $listing = $milestone->listing;
            $owner = $listing->owner;

            $fundNeeded= round(($milestone->amount - $milestone->pending_collected), 2);
            $amountPayable = $this->checkoutCalculator->stripe($fundNeeded);

            try{
                $charge = $this->Client->charges->create ([
                    "amount" => (int) round($amountPayable*100), //100 * 100,
                    "currency" => 'USD',
                    "source" => $request->stripeToken,
                    "description" => "This is an RMEP investment payment."
                ]);
                Log::info('Stripe charge created, charge_id: '. $charge->id);
            }
            catch (\Stripe\Exception\ApiErrorException $e) {
                ErrorLogService::report($e, [
                    'input' => request()->except(['password', 'token']),
                ]);

                Log::error('Stripe charge failed', ['error' => $e->getMessage()]);
                return response()->json(['message' => 'Payment could not be processed by Stripe'], 400);
            }

            $bid = BusinessBids::create([
                'date' => date('Y-m-d'),
                'investor_id' => $user->id, //Owner fills gap
                'ms_id' => $milestone_id,
                'business_id' => $listing->id,
                'owner_id' => $owner->id,
                'type' => 'Monetary',
                'method' => 'stripe',
                'amount' => $fundNeeded,
                'representation' => 0,
                'stripe_charge_id' => $charge->id
            ]);

            $milestone->increment('pending_collected', $fundNeeded);

            DB::beginTransaction();
            try {
                // L O G
                StripePayments::create([
                    'user_id' => $user->id,
                    'charge_id' => $charge->id,
                    'amount' => $fundNeeded,
                    'status' => 'settled',
                ]);

                //Transaction
                $this->transaction->create(
                    $user->id,'investment','stripe', $fundNeeded, $charge->id
                );

                DB::commit();
            }
            catch (\Exception $e) {
                DB::rollBack();
                ErrorLogService::report($e, [
                    'input' => request()->except(['password', 'token']),
                ]);

                Log::error('DB write failed after Stripe payment', ['error'=>$e->getMessage()]);
                return response()->json(['message'=>'Payment succeeded, but data could not be saved. Contact support.'], 500);
            }


            $total_bid_amount = $listing->bids()->sum('amount')
                + $listing->accepted_bids()->sum('amount')
                + $fundNeeded; // Pending + Accepted + Current

            // M i l e s t o n e   F u l f i l l   C h e c k
            if($total_bid_amount >= $milestone->amount && !$listing->threshold_met)
            {
                $listing->update(['threshold_met' => 1]);
                $info=[ 'business_name'=>$listing->name ];

                try{
                    Mail::send('bids.mile_fulfill', $info, function($msg) use ($owner){
                        $msg->to([$owner->email]);
                        $msg->subject('Fulfills a milestone.');
                    });
                }
                catch (\Exception $e){
                    ErrorLogService::report($e, [
                        'input' => request()->except(['password', 'token']),
                    ]);

                    Log::error('Mail to Owner send failed', ['error'=>$e->getMessage()]);
                }

                // NotificationService
                $text = 'A milestone for your business '.$listing->name.' can now be fulfilled. You can start reviewing/accepting bids as well.';
                $this->notification->create(
                    $owner->id, $user->id, $text, 'investment-bids', 'investor'
                );
            }

            return response()->json([
                'status' => 200,
                'message' => 'Bid placed!'
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

    #  G R A N T   &   C A P I T A L   D I S B U R S E M E N T S
    public function grantDisbursement(Request $request)
    {
        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!' ],401);
        }
        $user = Auth::user();

        try {
            $request->validate([
                'listing' => 'required|integer|exists:grant_milestones,id',
                'percent' => 'numeric|min:0|max:100',
                'stripeToken'  => 'required|string',
            ]);

            $milestone = GrantMilestone::findOrFail($request->listing);

            if ($milestone->status === 1){
                return response()->json(['message' => 'Payment already completed!'], 409);
            }

            $pitch = GrantApplication::with('grant')->where('id',$milestone->app_id)->first();

            if ($pitch->grant_owner_id !== $user->id) {
                return response()->json(['message' => 'Forbidden!'], 403);
            }

            $emails = User::whereIn('id', [$pitch->user_id, $pitch->grant_owner_id])
                ->pluck('email', 'id');
            $sme_email = $emails[$pitch->user_id];
            $grant_owner_email = $emails[$pitch->grant_owner_id];

            $connectIds = User::whereIn('id', [$pitch->user_id, $pitch->grant_owner_id])
                ->pluck('connect_id', 'id');
            $sme_connect_id = $connectIds[$pitch->user_id];

            if(!$sme_connect_id){
                return response()->json(['message' => 'Business owner is not able to receive funds via stripe,
                 please try other payment methods.'], 422);
            }

            $amount = (float) $milestone->amount;
            $amountPayable = round($amount, 2);
            //round($amount + ( $amount* ($tujitume_fee/100) ),2); // 5%

            if ($pitch->grant->available_amount < $amount) {
                return response()->json(['message' => 'Insufficient available funds.'], 400);
            }

            //T r a n s f e r
            if($request->percent == 100){
                $amount = $pitch->total_amount_requested;
                $amountPayable = round($amount, 2);
                //round($amount + ($amount * ($tujitume_fee / 100)), 2); // 5%
            }

            //C h a r g e
            try{
                $charge = $this->Client->charges->create ([
                    //"billing_address_collection": null,
                    "amount" => (int) round($amountPayable*100), //100 * 100,
                    "currency" => 'USD',
                    "source" => $request->stripeToken,
                    "description" => "Milestone Release Funds."
                ], [
                    'idempotency_key' => 'release_'.$milestone->id.'_'.now()->timestamp
                ]);
            }
            catch (\Stripe\Exception\ApiErrorException $e) {
                StripePayments::create([
                    'user_id' => $user->id,
                    'charge_id' => $charge?->id ?? null,
                    'amount' => $amountPayable ?? 0,
                    'status' => 'failed',
                ]);
                Log::error('Stripe charge failed', ['error' => $e->getMessage()]);
                return response()->json(['message' => 'Payment could not be processed by Stripe'], 400);
            }

            //D a t a b a s e
            //if($request->percent == 100){
            $milestone->update([
                'status' => 'disbursing',
                'fund_released' => true,
            ]);


            DB::beginTransaction();
            try{
                Grant::where('id', $pitch->grant_id)->update([
                    'available_amount' => DB::raw("available_amount - {$amount}")
                ]);

                //Update User Wallet
                $this->balance->updateBalance($pitch->user_id, (float)$amount, 'stripe');
                DB::commit();
            }
            catch(\Exception $e){
                DB::rollBack();
                return response()->json(['message' => 'Payment succeeded but Balance/Grant update failed. Admin notified.'], 500);
            }

            // Transactions & Stripe Log
            try {
                $this->transaction->create(
                    $user->id,'grant_milestone','stripe', $amountPayable, $charge->id, $pitch->user_id
                );

                StripePayments::create([
                    'user_id' => $user->id,
                    'charge_id' => $charge->id,
                    'amount' => $amountPayable,
                    'status' => 'settled',
                ]);
            } catch (\Exception $e) {
                Log::error('Transaction or StripePayments DB insert failed', [
                    'charge_id' => $charge->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // T r a n s f e r / Queue if fails/ Amount Calculation
            $amountToTransfer = $this->checkoutCalculator->stripeGC($amount); // $(91.30 of 100)

            if($charge && $charge->id){
                $transfer = $this->Client->transfers->create ([
                    "amount" => $amountToTransfer*100, //100 * 100,
                    "currency" => 'USD',
                    "source_transaction" => $charge->id,
                    'destination' => $sme_connect_id
                ]);

                // Update Disbursement Record with transfer id if needed
            }

            // E M A I L  & NotificationService
            try{
                $text = $milestone->title.' fund for '.$pitch->grant->grant_title.' has been released.';
                $this->notification->create($pitch->user_id,$pitch->grant->user_id,$text
                    ,'dashboard.entrepreneur.grantsDealroom.detail::'.$pitch->id,'grant');

                $info=[
                    'grant'=>$pitch->grant->grant_title,
                    'amount'=>$milestone->amount,
                    'milestone_title' => $milestone->title
                ]; $recipients ['to'] = [$grant_owner_email, $sme_email];

                Mail::send('opportunities.grant_milestone', $info, function($msg) use ($recipients){
                    $msg->to($recipients ['to']);
                    $msg->subject(' Grant Milestone Release');
                });
            }
            catch (\Exception $e){
                Log::error('Mail/NotificationService failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json(['message' => 'Fund Release Success.', 'status' =>200], 200);
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

    public function capitalDisbursement(Request $request)
    {
        if( !Auth::check() ){
            return response()->json(['message' => 'Unauthorized!' ],401);
        }
        $user = Auth::user();

        try {
            $request->validate([
                'listing' => 'required|integer|exists:capital_milestones,id',
                'percent' => 'numeric|min:0|max:100',
                'stripeToken'  => 'required|string',
            ]);

            $milestone = CapitalMilestone::findOrFail($request->listing);

            if ($milestone->status === 1){
                return response()->json(['message' => 'Payment already completed!'], 409);
            }

            $pitch = StartupPitches::with('capital_offer')->where('id',$milestone->app_id)->first();

            if ($pitch->capital_owner_id !== $user->id) {
                return response()->json(['message' => 'Forbidden!'], 403);
            }

            $emails = User::whereIn('id', [$pitch->user_id, $pitch->capital_owner_id])
                ->pluck('email', 'id');
            $sme_email = $emails[$pitch->user_id];
            $capital_owner_email = $emails[$pitch->capital_owner_id];

            $connectIds = User::whereIn('id', [$pitch->user_id, $pitch->capital_owner_id])
                ->pluck('connect_id', 'id');
            $sme_connect_id = $connectIds[$pitch->user_id];

            $amount = (float) $milestone->amount;
            $amountPayable = round($amount, 2);
            //round($amount + ( $amount* ($tujitume_fee/100) ),2); // 5%

            if ($pitch->capital_offer->available_amount < $amount) {
                return response()->json(['message' => 'Insufficient available funds.'], 400);
            }

            //T r a n s f e r
            if($request->percent == 100){
                $amount = $pitch->total_amount_requested;
                $amountPayable = round($amount, 2);
                //round($amount + ($amount * ($tujitume_fee / 100)), 2); // 5%
            }

            //C h a r g e
            try{
                $charge = $this->Client->charges->create ([
                    //"billing_address_collection": null,
                    "amount" => (int) round($amountPayable*100), //100 * 100,
                    "currency" => 'USD',
                    "source" => $request->stripeToken,
                    "description" => "Capital Milestone Release Funds."
                ], [
                    'idempotency_key' => 'release_'.$milestone->id.'_'.now()->timestamp
                ]);
            }
            catch (\Stripe\Exception\ApiErrorException $e) {
                StripePayments::create([
                    'user_id' => $user->id,
                    'charge_id' => $charge?->id ?? null,
                    'amount' => $amountPayable ?? 0,
                    'status' => 'failed',
                ]);
                Log::error('Stripe charge failed', ['error' => $e->getMessage()]);
                return response()->json(['message' => 'Payment could not be processed by Stripe'], 400);
            }

            //D a t a b a s e
            if($request->percent == 100){
                CapitalMilestone::where('app_id', $milestone->app_id)->update([
                    'status' => 1,
                    'fund_released' => 1,
                ]);
            }
            else{
                $milestone->update([ 'status' => 1 ]);
            }

            DB::beginTransaction();
            try{
                CapitalOffer::where('id', $pitch->capital_id)->update([
                    'available_amount' => DB::raw("available_amount - {$amount}")
                ]);

                //Update User Wallet
                $this->balance->updateBalance($pitch->user_id, (float)$amount, 'stripe');
                DB::commit();
            }
            catch(\Exception $e){
                DB::rollBack();
                return response()->json(['message' => 'Payment succeeded but Balance/Capital update failed. Admin notified.'], 500);
            }

            // Transactions & Stripe Log
            try {
                $this->transaction->create(
                    $user->id,'capital_milestone','stripe', $amountPayable, $charge->id, $pitch->user_id
                );

                StripePayments::create([
                    'user_id' => $user->id,
                    'charge_id' => $charge->id,
                    'amount' => $amountPayable,
                    'status' => 'settled',
                ]);
            } catch (\Exception $e) {
                Log::error('Transaction or StripePayments DB insert failed', [
                    'charge_id' => $charge->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            //T r a n s f e r to each SME and DB Amount Calculation
            $amountToTransfer = $this->checkoutCalculator->stripeGC($amount); // $(91.30 of 100)
            if($charge && $charge->id){
                $transfer = $this->Client->transfers->create ([
                    "amount" => $amountToTransfer*100, //100 * 100,
                    "currency" => 'USD',
                    "source_transaction" => $charge->id,
                    'destination' => $sme_connect_id
                ]);
            }

            // E M A I L  & NotificationService
            try{
                $text = $milestone->title.' fund for '.$pitch->capital_offer->offer_title.' has been released.';
                $this->notification->create($pitch->user_id,$pitch->capital_offer->user_id, $text
                    ,'overview/funding/funding?pitch_id='.$pitch->id,' capital');

                $info=[
                    'capital'=>$pitch->capital_offer->offer_title,
                    'amount'=>$milestone->amount,
                    'milestone_title' => $milestone->title
                ]; $recipients ['to'] = [$capital_owner_email, $sme_email];

                Mail::send('opportunities.capital_milestone', $info, function($msg) use ($recipients){
                    $msg->to($recipients ['to']);
                    $msg->subject(' Capital Milestone Release');
                });
            }
            catch (\Exception $e){
                Log::error('Mail/NotificationService failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json(['message' => 'Fund Release Success.', 'status' =>200], 200);
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


//Class Ends
}
