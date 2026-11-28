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
use App\Models\Programs\ProgramMilestone;
use App\Models\Milestones\Milestones;
use App\Models\Misc\Setting;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\LiprMpesa\ProgramDisbursementService;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use App\Service\Services\ServiceBookingFlow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class MpesaPollingController extends Controller
{

    public function __construct(ProgramDisbursementService $disbursementService)
    {

        parent::__construct();

        $this->balance = new BalanceService();
        $this->liprW2W = app(LiprW2W::class);
        $this->disbursementService = $disbursementService;
        $this->tujitume_lipr = Setting::where('key', 'platform_lipr_wallet')->first()?->value ?? null;
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
            // The checkout records the payment as pending when it starts: until LIPR's result arrives the answer is
            // "pending", not an error (it used to be a 422 "not ready" on every poll)
            if (! in_array(strtolower((string) $paymentExists->status), ['successful', 'completed'], true)) {
                return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
            }

            // The investor's own unlock payment only (rows from before the checkout recorded the payer: the first poll claims it)
            if (($paymentExists->user_id && (int) $paymentExists->user_id !== (int) $investor->id)
                || ($paymentExists->purpose && $paymentExists->purpose !== 'small_fee')) {
                return response()->json(['message' => 'This payment is not yours.'], 403);
            }
            if (!$paymentExists->user_id) {
                $paymentExists->forceFill(['user_id' => $investor->id, 'purpose' => 'small_fee'])->save();
            }
            // Polled again after the business was unlocked: the same answer, not an error
            if (strtolower((string) $paymentExists->status) === 'completed'
                && Conversation::where('investor_id', $investor->id)->where('listing_id', $business_id)->exists()) {
                return response()->json(['status' => 'completed', 'updated_at' => now()], 200);
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

                // If Processed: the unlock fee is a fixed KSh amount, so it is checked against the KSh really paid
                $unlockAmount = (float) $listing->investors_fee;
                $paidAmount = (float) $payment->amount;
                if ($unlockAmount > 0 && $paidAmount < $unlockAmount * 0.99) {
                    throw new \Exception('The payment is less than this business\'s unlock fee.', 422);
                }

                Conversation::create([
                    'investor_id' => $investor->id,
                    'listing_id' => $business_id,
                    'package' => null,
                    'price' => $unlockAmount
                ]);

                //L i p r  Transfer API from Tujitume to Sme wallet
                $amountKes = round($unlockAmount, 2); // already KSh

                $transfer = $this->liprW2W->send(
                    $amountKes, $listing->owner->lipr_wallet, $this->tujitume_lipr, 'Unlocking business fee'
                );

                // The investor paid, so the business is unlocked either way; the owner is credited only when their
                // share really reaches them (a failed transfer is logged to be paid out again, never faked as sent)
                if (!empty($transfer['success'])) {
                    $this->balance->updateBalance($listing->user_id, $unlockAmount, 'lipr');
                } else {
                    Log::warning('Unlock fee transfer to the business owner failed', ['listing' => $listing->id, 'reference' => $referenceId, 'error' => $transfer['message'] ?? $transfer['errors'] ?? null]);
                }

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
                'amountUSD' => 'required|numeric|min:1', // the full bid; the payment is its 25% deposit
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
            // The checkout records the payment as pending when it starts: until LIPR's result arrives the answer is
            // "pending", not an error (it used to be a 422 "not ready" on every poll)
            if (! in_array(strtolower((string) $paymentExists->status), ['successful', 'completed'], true)) {
                return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
            }

            // The payment must be this investor's own bid payment; the bid is worth what was really paid, never what the browser says
            // (rows from before the checkout recorded the payer have none: the first poll claims it)
            if (($paymentExists->user_id && (int) $paymentExists->user_id !== (int) $investor_id)
                || ($paymentExists->purpose && $paymentExists->purpose !== 'bids')) {
                return response()->json(['message' => 'This payment is not yours.'], 403);
            }
            if (!$paymentExists->user_id) {
                $paymentExists->forceFill(['user_id' => $investor_id, 'purpose' => 'bids'])->save();
            }
            // An investment is paid 25% up front (the rest after the owner accepts): the bid is the investor's chosen
            // amount only when what was really paid covers 25% of it. A business's money (goal, milestones, bids) is in
            // KSh, as the business form and the checkout show it, so the KSh really paid is what is compared (amountUSD
            // is the request's old name for the bid amount)
            $paidUsd = round((float) $paymentExists->amount, 2);
            $bidUsd = round((float) $request->amountUSD, 2);
            if ($paidUsd <= 0 || $paidUsd < $bidUsd * 0.25 * 0.99) {
                return response()->json(['message' => 'The payment does not cover the 25% deposit for this bid.'], 422);
            }
            $paidShare = $business->investment_needed > 0
                ? round($bidUsd / (float) $business->investment_needed * (float) ($business->share ?? 0), 4)
                : 0;

            // Polled again after the bid was made: the same answer, not an error
            if (strtolower((string) $paymentExists->status) === 'completed'
                && BusinessBids::where('lipr_transaction_id', $referenceId)->where('investor_id', $investor_id)->exists()) {
                return response()->json(['status' => 'completed', 'updated_at' => now()], 200);
            }

            $amountFromDb = $paymentExists->amount;
            if ($request->amountPaid != $amountFromDb)
                return response()->json([
                    'error_in' => $request->amountPaid .'!='. $amountFromDb,
                    'message' => 'Amount does not match the original amount!'. $request->amountPaid .'!='. $amountFromDb,
                ], 400);

            DB::transaction(function() use($request, $investor, $owner, &$paymentExists, $business, $referenceId, $investor_id, $paidUsd, $bidUsd, $paidShare)
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
                    'amount' => $bidUsd,
                    'representation' => $paidShare,
                    'lipr_transaction_id' => $referenceId
                ]);


                // The milestone now taking money is the first one not yet fully funded (the same rule the owner's
                // accept uses); the bid counts as pending on it until it is accepted
                $mile1 = Milestones::select('id', 'listing_id', 'amount', 'funding_collected', 'pending_collected')
                    ->where('listing_id', $business->id)->orderBy('id')->get()
                    ->first(fn ($m) => (float) $m->funding_collected < (float) $m->amount);

                if ($mile1) {
                    $bids->update(['ms_id' => $mile1->id]);
                    $mile1->increment('pending_collected', $bidUsd);
                    $mile1->refresh();

                    // Milestone Fulfill check: pending bids plus accepted money cover it, so the owner can accept
                    if (!$business->threshold_met && ((float) $mile1->pending_collected + (float) $mile1->funding_collected) >= (float) $mile1->amount) {
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

            // The business owner is emailed too, not only told in the app
            try {
                $ownerEmail = $owner->email;
                if ($ownerEmail) {
                    Mail::send('bids.new_bid_owner', [
                        'owner_name' => $owner->first_name, 'investor_name' => trim($investor->first_name.' '.$investor->last_name),
                        'business_name' => $business->name, 'type' => 'Monetary',
                        'amount' => config('app.currency', 'KES').' '.number_format($bidUsd, 2), // the listing's own currency (KSh), not USD
                        'action_url' => \App\Service\Notification\EmailLink::url('investment-bids', $owner), 'action_label' => 'Review the bid',
                    ], function ($msg) use ($ownerEmail) {
                        $msg->to($ownerEmail);
                        $msg->subject('You Have a New Bid');
                    });
                }
            } catch (\Throwable $e) {
                Log::warning('New bid email to the owner failed: '.$e->getMessage());
            }

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
            // The checkout records the payment as pending when it starts: until LIPR's result arrives the answer is
            // "pending", not an error (it used to be a 422 "not ready" on every poll)
            if (! in_array(strtolower((string) $paymentExists->status), ['successful', 'completed'], true)) {
                return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
            }

            $bid = AcceptedBids::with('listing.owner')->where('id', $bid_id)->first();
            if (!$bid || (int) $bid->investor_id !== (int) $investor->id) {
                return response()->json(['message' => 'Unauthorized action.'], 403);
            }
            // The investor's own 75% payment only (rows from before the checkout recorded the payer: the first poll claims it)
            if (($paymentExists->user_id && (int) $paymentExists->user_id !== (int) $investor->id)
                || ($paymentExists->purpose && $paymentExists->purpose !== 'awaiting_payment')) {
                return response()->json(['message' => 'This payment is not yours.'], 403);
            }
            if (!$paymentExists->user_id) {
                $paymentExists->forceFill(['user_id' => $investor->id, 'purpose' => 'awaiting_payment'])->save();
            }
            if (strtolower((string) $paymentExists->status) === 'completed' && $bid->lipr_transaction_id === $referenceId) {
                return response()->json(['status' => 'completed', 'updated_at' => now()], 200);
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

                // If Processed: the KSh really paid must cover the remaining 75% of the accepted (KSh) amount
                $paidAmount = (float) $payment->amount;
                $due = round((float) $bid->amount * 0.75, 2);
                if ((float) $paidAmount < $due * 0.99) {
                    throw new \Exception('The payment is less than the 75% still due on this investment.', 422);
                }
                $bid->update([
                    'paid_in_full' => true,
                    'status' => 'confirmed',
                    'lipr_transaction_id' => $referenceId
                ]);
                $payment->update([ 'status' => 'completed' ]);

                //Transaction
                $this->transaction->create(
                    $investor->id,'investment_awaiting','lipr', $payment->amount, $referenceId, $bid->listing->owner->id
                );

            });

            // The milestone's funding progress counts confirmed money only: it is worked out again now, so a milestone this
            // payment completes is fully funded straight away (it used to wait until the owner opened their milestones,
            // and until then the investors could not ask for its pre-release)
            try {
                (new \App\Service\Business\Milestone\MilestoneLifecycleService())->evaluateListing($bid->listing);
            } catch (\Throwable $e) {
                ErrorLogService::report($e, ['accepted_bid' => $bid->id]);
            }

            //NotificationService
            $text = 'Your bid to business '.$bid->listing->name.' is confirmed.';
            // Opens the investor's My Investments (the notification resolver maps 'my-investments' for type 2)
            $this->notification->create($investor->id,$bid->owner_id,$text,'my-investments','business');
            // The business owner learns the investment is now fully paid
            $this->notification->create($bid->owner_id, $investor->id,
                '_name paid the rest of their investment in '.($bid->listing->name ?? 'your business').': it is now confirmed.',
                'investment-bids', 'investor');

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



    /**
     * The checkout's poll after paying a booked service by M-Pesa (purpose s_mile). listing_id (the checkout's name) or
     * business_id (older screens) is one of the booking's milestones. Once Lipr reports the payment successful, and it
     * covers what the booking costs, the booking is recorded as paid (ServiceBookingFlow::markPaid, once per payment).
     */
    public function status_service(Request $request)
    {
        if (! Auth::check()) {
            return response()->json(['message' => 'Unauthorized!'], 401);
        }
        $customer = Auth::user();

        try {
            $request->merge(['business_id' => $request->input('business_id', $request->input('listing_id'))]);
            $request->validate([
                'business_id' => 'required|integer|exists:service_booking_milestones,id',
                'referenceId' => 'required|string',
            ]);

            $milestone = ServiceBookingMilestone::with('booking.service.owner')->findOrFail($request->business_id);
            $booking = $milestone->booking;
            $service = $booking?->service;
            if (! $booking || ! $service) {
                return response()->json(['message' => 'Booking not found.'], 404);
            }
            if ((int) $booking->booker_id !== (int) $customer->id) {
                return response()->json(['message' => 'Unauthorized payment.'], 403);
            }

            $referenceId = (string) $request->referenceId;
            // The same payment polled again after it was recorded
            if ($booking->paid && $booking->payment_reference === $referenceId) {
                return response()->json(['status' => 'completed', 'updated_at' => now()], 200);
            }

            $payment = LiprPayment::where('reference_id', $referenceId)->first();
            if (! $payment) {
                return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
            }
            $status = strtolower((string) $payment->status);
            if ($status === 'failed') {
                return response()->json(['status' => 'failed', 'updated_at' => now(), 'message' => 'Customer rejected payment or did not pay'], 200);
            }
            if ($status !== 'successful') {
                return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
            }

            try {
                ServiceBookingFlow::assertPayable($booking, $customer);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], $e->getCode() === 409 ? 409 : 422);
            }

            // What was paid must cover the service (a shilling of rounding is allowed)
            $expected = ServiceBookingFlow::charge($booking);
            if ((float) $payment->amount + 1 < ServiceBookingFlow::amountDue($booking)) {
                Log::warning('Service payment smaller than the booking', ['booking' => $booking->id, 'paid' => $payment->amount, 'expected' => $expected]);
                return response()->json(['message' => 'The amount paid does not cover this service. Contact support with your payment reference.'], 422);
            }

            $recorded = (new ServiceBookingFlow())->markPaid($booking, $customer, 'lipr', $referenceId, (float) $payment->amount);
            $payment->update(['status' => 'completed']);

            if ($recorded) {
                $this->afterServicePaid($booking->refresh(), $service, $customer, $milestone, (float) $payment->amount);
            }

            return response()->json([
                'status' => 'completed',
                'booking_id' => $booking->id,
                'updated_at' => now(),
            ], 200);
        }
        catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            if (in_array($e->getCode(), [404, 409, 422])) {
                return response()->json(['message' => $e->getMessage()], $e->getCode());
            }

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /** A project-management service bought for an investment bid assigns its provider as the bid's manager; then the emails. */
    private function afterServicePaid(ServiceBooking $booking, $service, User $customer, ServiceBookingMilestone $milestone, ?float $paid = null): void
    {
        $mailJobs = [];
        $bid = $booking->business_bid_id ? AcceptedBids::find($booking->business_bid_id) : null;
        if ($bid && ($service->category == '0' || $service->category == 'project_management') && $bid->listing?->owner && $service->owner) {
            $business_owner = $bid->listing->owner;
            $bid->update(['status' => 'manager_assigned', 'project_manager' => $service->owner->id]);
            $investor_name = $customer->first_name.' '.$customer->last_name;
            $manager = $service->owner->first_name.' '.$service->owner->last_name;
            $mailJobs[] = ['view' => 'bids.owner_manager_alert', 'to' => $business_owner->email, 'subject' => 'Project Manager Assigned!',
                'info' => ['mail_to' => 'owner', 'manager_name' => $manager, 'contact' => $service->owner->email, 'investor_name' => $investor_name]];
            $mailJobs[] = ['view' => 'bids.owner_manager_alert', 'to' => $service->owner->email, 'subject' => 'Project Manager Assigned!',
                'info' => ['mail_to' => 'manager', 'contact' => $business_owner->email, 'investor_name' => $investor_name]];
            try {
                $this->notification->createWithBidId($business_owner->id, $service->owner->id, $bid->id,
                    'Project Manager '.$manager.' has been assigned to help verify the equipment from the investor '.$investor_name, 'verify_request_manager', 'business');
                $this->notification->createWithBidId($service->owner->id, $business_owner->id, $bid->id,
                    'You been assigned to help verify the equipment from the investor '.$investor_name, '/', 'business');
            } catch (\Throwable $e) {
                Log::warning('Project manager notification failed: '.$e->getMessage());
            }
        }

        // One email each: the customer sees what they paid (the service fee plus the checkout fees, as charged), the provider
        // sees the service fee that is released to them milestone by milestone. Each opens their own bookings page.
        $info = ['name' => $milestone->title, 'amount' => ServiceBookingFlow::amountDue($booking), 'business' => $service->name, 's_id' => $service->id,
            'customer' => $customer->first_name, 'id' => $booking->id, 'note' => $milestone->note];
        $mailJobs[] = ['view' => 'milestoneS.milestone_mail', 'to' => $customer->email, 'subject' => 'Service Payment Received',
            'info' => $info + ['paid' => $paid, 'for' => 'customer', 'link' => ServiceBookingFlow::customerLink($booking)]];
        if ($service->owner?->email) {
            $mailJobs[] = ['view' => 'milestoneS.milestone_mail', 'to' => $service->owner->email, 'subject' => 'Service Payment Received',
                'info' => $info + ['for' => 'provider', 'link' => \App\Service\Notification\EmailLink::url('dashboard.serviceProvider.serviceBookings', $service->owner)]];
        }

        foreach ($mailJobs as $job) {
            try {
                Mail::send($job['view'], $job['info'], function ($msg) use ($job) {
                    $msg->to($job['to']);
                    $msg->subject($job['subject']);
                });
            } catch (\Throwable $e) {
                Log::warning('Mail sending failed: '.$e->getMessage());
            }
        }
    }


    // G R A N T S  &  C A P I TA L

    public function programDisbursementStatus(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthorized!'], 401);
        }

        try {
            $request->validate([
                'milestone_id' => 'required|numeric|exists:program_milestones,id',
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

            $milestone = ProgramMilestone::find($request->milestone_id);

            // A direct wallet-funded release completes without setting the application escrow flag.
            if ($milestone->fund_release_status === 'released' && $milestone->fund_released) {
                return response()->json([
                    'status' => 'completed',
                    'message'        => 'Payment completed successfully.',
                    'updated_at'     => now(),
                ], 200);
            }

            if (!$milestone->application->escrow_funded) {
                return response()->json(['status' => 'pending'], 200);
            }

            // Leg 1 successful, Leg 2 processing
            if (strtolower($payment->status) === 'successful' || $milestone->fund_release_status === 'processing') {
                return response()->json([
                    'status'     => 'disbursing',  // â† clean lowercase
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


    /**
     * POST /lipr-capital-disbursement-status {milestone_id, referenceId}: polled after the capital provider paid a tranche
     * by M-Pesa (lipr/initiate-payment purpose capital_milestone charges the amount plus the fee). Once the payment is in,
     * the full milestone amount is sent to the entrepreneur's LIPR wallet. The release is recorded (processing) before the
     * transfer and put back when the transfer fails, so a payment is never released twice nor lost.
     * status: pending | failed | completed
     */
    public function capitalDisbursementStatus(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized!'], 401);
        }

        $request->validate([
            'milestone_id' => 'required|numeric|exists:capital_milestones,id',
            'referenceId' => 'required',
        ]);
        $referenceId = (string) $request->referenceId;

        $payment = LiprPayment::where('reference_id', $referenceId)->first();
        if (! $payment) {
            return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
        }
        if (strtolower((string) $payment->status) === 'failed') {
            return response()->json(['status' => 'failed', 'updated_at' => now(), 'message' => 'Customer rejected payment or did not pay'], 200);
        }

        $milestone = CapitalMilestone::find($request->milestone_id);
        if ($milestone->fund_released && $milestone->release_reference === $referenceId) {
            return response()->json(['status' => 'completed', 'updated_at' => now()], 200);
        }
        if ($refusal = \App\Service\Capital\CapitalRelease::refusal($user, $milestone)) {
            return response()->json(['message' => $refusal[0]], $refusal[1]);
        }
        if (strtolower((string) $payment->status) === 'completed') {
            return response()->json(['message' => 'This payment was already used.'], 409);
        }
        if (strtolower((string) $payment->status) !== 'successful') {
            return response()->json(['status' => 'pending', 'updated_at' => now()], 200);
        }

        $pitch = StartupPitches::with(['capital_offer', 'sme'])->find($milestone->app_id);
        $milestones = collect([$milestone]);
        $amountKes = \App\Service\Capital\CapitalRelease::amount($milestones);
        $fee = \App\Service\Capital\CapitalRelease::fee($amountKes, 'mpesa');

        // The payment must be this release's: a capital milestone payment, made by this provider, covering amount + fee.
        // Without this any successful payment reference (e.g. a small fee paid elsewhere) could unlock a release.
        if ($payment->purpose && $payment->purpose !== 'capital_milestone') {
            return response()->json(['message' => 'This payment was not made for a capital release.'], 422);
        }
        if ($payment->user_id && (int) $payment->user_id !== (int) $user->id) {
            return response()->json(['message' => 'This payment was made by someone else.'], 403);
        }
        if ((float) $payment->amount + 1 < $amountKes + $fee) {
            return response()->json(['message' => 'The payment (KES '.number_format((float) $payment->amount, 2).') does not cover this release of KES '
                .number_format($amountKes, 2).' plus the KES '.number_format($fee, 2).' fee.'], 422);
        }

        if (! $pitch->sme?->lipr_wallet) {
            return response()->json(['message' => 'The business owner has no LIPR wallet to receive the funds yet.'], 422);
        }

        // 1. the record, before any money
        try {
            \App\Service\Capital\CapitalRelease::begin($milestones, $referenceId, $fee);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        // 2. the full amount to the entrepreneur
        try {
            $transfer = $this->liprW2W->send($amountKes, $pitch->sme->lipr_wallet, $this->tujitume_lipr, 'Capital Milestone Disbursement');
        } catch (\Throwable $e) {
            $transfer = ['success' => false, 'errors' => [$e->getMessage()]];
        }
        if (! $transfer || empty($transfer['success'])) {
            \App\Service\Capital\CapitalRelease::fail($milestones);
            $reason = $transfer ? ($transfer['errors'][0] ?? 'The transfer was refused.') : 'The platform LIPR wallet is not set up.';

            return response()->json(['message' => is_string($reason) ? $reason : 'The transfer was refused.'], 422);
        }

        // 3. the release, in one database transaction
        try {
            \App\Service\Capital\CapitalRelease::complete($pitch, $milestones, 'lipr', $referenceId, $fee, function () use ($referenceId, $pitch, $amountKes) {
                LiprPayment::where('reference_id', $referenceId)->update(['status' => 'completed']);
                $this->balance->updateBalance($pitch->user_id, $amountKes, 'lipr');
            });
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['capital_release' => $milestone->id, 'reference' => $referenceId]);

            return response()->json(['message' => 'The funds were sent; the release is being finalised. Admin notified.'], 500);
        }

        \App\Service\Capital\CapitalRelease::notifyReleased($pitch, $milestones);

        return response()->json(['status' => 'completed', 'amount' => $amountKes, 'fee' => $fee, 'updated_at' => now()], 200);
    }


}
