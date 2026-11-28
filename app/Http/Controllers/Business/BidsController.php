<?php

namespace App\Http\Controllers\Business;


use Exception;
use App\Events\NewNotification;
use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\Listing;
use App\Models\Communication\Notifications;
use App\Models\Finance\Transactions;
use App\Models\Milestones\Milestones;
use App\Models\Misc\Setting;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\Business\BidList;
use App\Service\Business\Bid\AgreeToProgressVotingService;
use App\Service\Business\Bid\BusinessBidService;
use App\Service\Business\Bid\PendingAndAssetBidService;
use App\Service\File\ImageCompressor;
use App\Service\File\ImageUploadService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use App\Support\ListShapes;
use Carbon\Carbon;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mail;
use Session;
use Stripe\StripeClient;


class BidsController extends Controller
{
    protected $Client;
    protected BalanceService $balance;
    protected LiprW2W $liprW2W;
    protected $convert;
    protected $tujitume_lipr;

    public function __construct(StripeClient $client)
    {
        parent::__construct();

        $this->Client = $client;
        $this->balance = new BalanceService();
        $this->liprW2W = new LiprW2W();
        $this->convert = new CurrencyConverter();
        $this->tujitume_lipr = Setting::where('key', 'tujitume_lipr_wallet')->first()?->value ?? null;

    }

    public function bidInfo($id)
    {
        try{
            $bid = AcceptedBids::where('id', $id)->first();
            // Only the investor and the business owner may read an investment
            if (!$bid || !in_array((int) Auth::id(), [(int) $bid->investor_id, (int) $bid->owner_id], true)) {
                return response()->json(['message' => 'Investment not found.'], 404);
            }
            $investor = User::select('first_name')->where('id', $bid->investor_id)
                ->first()?->first_name;
            return response()->json([
                'data' => $bid,
                'investor'=> $investor
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

    public function businessBids(Request $request)
    {
        // The Pending tab as a server list (page, search, filters, sort, export); no params = the full list as before
        if (ListShapes::wanted($request)) {
            return (new BidList((int) Auth::id(), 'pending'))->respond($request);
        }

        try {
            $bids = BusinessBids::with(['investor', 'listing'])
                ->where('owner_id', Auth::id())
                ->latest()->get();

            $result = $bids->filter(fn($bid) => $bid->investor && $bid->listing)
                ->map(function ($bid) {
                    $inv = $bid->investor;
                    return array_merge($bid->toArray(), [
                        'investor'        => $inv->first_name . ' ' . $inv->last_name,
                        'investor_name'   => trim($inv->first_name . ' ' . $inv->mname . ' ' . $inv->last_name),
                        'business'        => $bid->listing->name,
                        'threshold'       => $bid->listing->threshold_met,
                        'inv_range'       => $inv->inv_range,
                        'interested_cats' => $inv->interested_cats,
                        'past_investment' => $inv->past_investment,
                        'website'         => $inv->website,
                        'email'           => $inv->email,
                        'status'          => 'Pending',
                        'milestone'       => $bid->listing->active_milestone()?->title ?? '',
                        'photos'          => explode(',', $bid->photos),
                    ]);
                })->values();
            BusinessBids::where('owner_id', Auth::id())->update(['new' => 0]);

            return response()->json(['bids' => $result]);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['user_id' => Auth::id()]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function confirmedBids(Request $request)
    {
        // The Active / Under verification tabs as server lists (?stage=active|under-verification)
        if (ListShapes::wanted($request)) {
            return (new BidList((int) Auth::id(), $request->query('stage') === 'under-verification' ? 'under-verification' : 'active'))->respond($request);
        }

        try {
            $confirmed = AcceptedBids::with(['investor', 'listing'])
                ->where('owner_id', Auth::id())
                ->whereIn('status', ['Confirmed', 'verified'])
                ->latest()->get();

            $underVerification = AcceptedBids::with(['investor', 'listing'])
                ->where('owner_id', Auth::id())
                ->whereIn('status', ['under_verification', 'manager_assigned', 'equipment_released'])
                ->latest()->get();

            $formatBid = function ($bid) {
                if (!$bid->investor || !$bid->listing) return null;
                $inv = $bid->investor;
                return array_merge($bid->toArray(), [
                    'investor'        => $inv->first_name . ' ' . $inv->last_name,
                    'business'        => $bid->listing->name,
                    'interested_cats' => $inv->interested_cats,
                    'past_investment' => $inv->past_investment,
                    'website'         => $inv->website,
                    'email'           => $inv->email,
                    'milestone'       => $bid->listing->active_milestone()?->title ?? '',
                    'photos'          => explode(',', $bid->photos),
                ]);
            };

            return response()->json([
                'bids'        => $confirmed->map($formatBid)->filter()->values(),
                'underVerify' => $underVerification->map($formatBid)->filter()->values(),
            ]);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['user_id' => Auth::id()]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // Accept or Reject Bids POST
    public function accept(Request $request)
    {
        $bidService = new BusinessBidService($this->Client);

        try {

            $validated = $request->validate([
                'bid_ids' => 'required|array',
                'bid_ids.*' => 'integer|exists:business_bids,id',
                'reject' => 'nullable|boolean'
            ]);

            if ($request->boolean('reject')) {
                return $bidService->rejectBids($validated['bid_ids']);
            }

            return $bidService->acceptBids($validated['bid_ids']);

        }
        catch (ValidationException $e) {
            return response()->json([ 'message' => $e->errors() ], 422);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [ 'input' => request()->except(['password','token']) ]);
            // A refund that could not be sent says so (the bid is left as it was)
            if (in_array($e->getCode(), [422, 502], true)) {
                return response()->json(['message' => $e->getMessage()], $e->getCode());
            }

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function agreeToProgressWithMilestone($bidId)
    {
        // A public email link (the id is only base64): it no longer votes or releases anything by itself. Investors
        // decide in their Deal Room, signed in (pre-release, mid-milestone and final approval votes).
        $bid = AcceptedBids::find((int) base64_decode((string) $bidId)) ?? AcceptedBids::find((int) $bidId);
        $investor = $bid ? User::find($bid->investor_id) : Auth::user();

        return redirect()->to(\App\Service\Notification\EmailLink::url('dealroom', $investor) ?? \App\Service\Notification\RecipientUrl::url('dashboard', $investor));
    }

    // Withdraw Accepted Investment
    public function withdraw_investment($bidId)
    {
        try {

            DB::beginTransaction();
            $bidService = new BusinessBidService($this->Client);

            $bid = AcceptedBids::with('milestone')->find($bidId);
            if (!$bid || (int) $bid->investor_id !== (int) Auth::id()) {
                DB::rollBack();
                return response()->json(['message' => 'Investment not found.'], 404); // only the investor can withdraw it
            }
            if ($bid->milestone && ($bid->milestone->fund_released || $bid->milestone->fund_released_75)) {
                DB::rollBack();
                return response()->json(['message' => 'This milestone\'s money has already been released to the business, so the investment can no longer be withdrawn.'], 409);
            }
            $investor = User::select('id','first_name','last_name','email')
                ->findOrFail($bid->investor_id);

            $business = Listing::findOrFail($bid->business_id);

            $owner = User::select('id','first_name','last_name','email')
                ->findOrFail($business->user_id);

            $investorName = $investor->first_name.' '.$investor->last_name;

            // Handle refund if monetary
            if ($bid->type === 'Monetary') {
                $bidService->handleWithdrawRefund($bid);
            }


            $business->update([
                'amount_collected' => $business->amount_collected - $bid->amount,
                'invest_count'     => $business->invest_count - 1
            ]);

            $bid->milestone?->decrement('funding_collected', $bid->amount);


            /*| Notifications */

            $this->createNotification(
                $owner->id,
                $investor->id,
                'A bid to business '.$business->name.' was withdrawn by '.$investorName,
                '/',
                'business'
            );

            $this->createNotification(
                $investor->id,
                $owner->id,
                'Your bid to business '.$business->name.' was withdrawn, you will get the refund in 7 business days',
                '/',
                'business'
            );

            /*
            |--------------------------------------------------------------------------
            | Remove Bid
            |--------------------------------------------------------------------------
            */
            $bid->delete();

            /*
            | Threshold Check
            */

            $threshold = $bidService->checkThreshold($business, $bid);

            DB::commit();

            return response()->json([
                'message' => 'Bid Withdrawn! Refund will be processed in 7 business days.',
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            ErrorLogService::report($e, ['input' => request()->except(['password','token']),]);
            if (in_array($e->getCode(), [422, 502], true)) {
                return response()->json(['message' => $e->getMessage()], $e->getCode());
            }
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function withdrawPendingInvestment($id)
    {
        $bid = BusinessBids::find($id);
        if (!$bid || (int) $bid->investor_id !== (int) Auth::id()) {
            return response()->json(['message' => 'Bid not found.'], 404); // only the investor who made it can withdraw it
        }

        try {
            $owner = User::select('id', 'email', 'first_name')->findOrFail($bid->owner_id);
            $investor = User::select('id', 'first_name', 'last_name', 'lipr_wallet')->findOrFail($bid->investor_id);
            $invName = trim($investor->first_name.' '.$investor->last_name);
            $listing = Listing::find($bid->business_id);

            // Refund first: the bid is only removed once its deposit is on its way back
            if ($bid->type === 'Monetary') {
                if ($bid->method === 'lipr') {
                    $payment = \App\Models\Finance\LiprPayment::where('reference_id', $bid->lipr_transaction_id)->first();
                    $refundKes = (float) ($payment->amount ?? 0);
                    $platformWallet = Setting::where('key', 'platform_lipr_wallet')->value('value');
                    if (!$investor->lipr_wallet) {
                        return response()->json(['message' => 'Set up your wallet first, so your deposit can be refunded to it.'], 422);
                    }
                    if ($refundKes <= 0 || !$platformWallet) {
                        return response()->json(['message' => 'The refund could not be worked out. Please contact support.'], 422);
                    }
                    $refund = app(LiprW2W::class)->send((int) round($refundKes), $investor->lipr_wallet, $platformWallet, null, [
                        'narration' => 'Bid deposit refund', 'listing_id' => $bid->id,
                    ]);
                    if (empty($refund['success'])) {
                        return response()->json(['message' => 'The refund could not be sent right now; your bid is unchanged. Please try again.'], 502);
                    }
                    (new \App\Service\Misc\TransactionRecord())->create($investor->id, 'refund', 'lipr', $refundKes, $refund['reference'] ?? null, $investor->id);
                } elseif ($bid->method === 'stripe' && $bid->stripe_charge_id) {
                    $this->Client->refunds->create(['charge' => $bid->stripe_charge_id]);
                }
            } else {
                foreach (['legal_doc', 'optional_doc', 'photos'] as $f) {
                    if ($bid->$f && is_string($bid->$f) && file_exists($bid->$f)) unlink($bid->$f);
                }
            }

            DB::transaction(function () use ($bid, $listing) {
                // The bid counted as pending on its milestone until now
                if ($bid->ms_id) {
                    Milestones::where('id', $bid->ms_id)->where('pending_collected', '>=', $bid->amount)->decrement('pending_collected', $bid->amount);
                }
                $bid->delete();
            });

            if ($listing) {
                (new BusinessBidService($this->Client))->checkThreshold($listing, $bid);
            }

            $this->notification->create($owner->id, $bid->investor_id, 'A bid to business '.($listing->name ?? '').' was cancelled by '.$invName, 'investment-bids', 'investor');
            $this->notification->create($bid->investor_id, $owner->id, 'Your bid to business '.($listing->name ?? '').' was cancelled and your deposit refunded.', 'my-investments', 'business');
            (new \App\Service\Notification\EmailService())->send('Bid Cancelled', 'bids.cancelled', ['business_name' => $listing->name ?? '', 'investor' => $invName], $owner->email);

            return response()->json(['status' => 200, 'message' => 'Bid withdrawn and your deposit refunded.'], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    // optional from milestone done email click
    public function agreeToNextmile($bidId)
    {
        // A public email link (the id is only base64): it no longer votes or releases anything by itself. Investors
        // decide in their Deal Room, signed in (pre-release, mid-milestone and final approval votes).
        $bid = AcceptedBids::find((int) base64_decode((string) $bidId)) ?? AcceptedBids::find((int) $bidId);
        $investor = $bid ? User::find($bid->investor_id) : Auth::user();

        return redirect()->to(\App\Service\Notification\EmailLink::url('dealroom', $investor) ?? \App\Service\Notification\RecipientUrl::url('dashboard', $investor));
    }



    public function createNotification($receiver_id,$customer_id,$text,$link,$type)
    {
        $now=date("Y-m-d H:i"); $date=date('d M, h:i a',strtotime($now));
        $addNoti = Notifications::create([
            'date' => $date,
            'receiver_id' => $receiver_id,
            'customer_id' => $customer_id,
            'text' => $text,
            'link' => $link,
            'type' => $type,
        ]);

        // Dispatch real-time event
        event(new NewNotification([
            'text' => $text,
            'link' => $link,
            'type' => $type,
            'date' => $date,
            'customer_id' => $customer_id,
        ], $receiver_id));
    }

    //Class Ends Below
}
