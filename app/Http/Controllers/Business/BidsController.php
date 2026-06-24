<?php

namespace App\Http\Controllers\Business;

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
use App\Models\Services\ServiceBook;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\Business\Bid\AgreeToProgressVotingService;
use App\Service\Business\Bid\BusinessBidService;
use App\Service\Business\Bid\PendingAndAssetBidService;
use App\Service\File\ImageCompressor;
use App\Service\File\ImageUploadService;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
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
        $this->tujitume_lipr = Setting::where('key', 'tujitume_lipr_wallet')->first()->value;

    }

    public function bidInfo($id)
    {
        try{
            $bid = AcceptedBids::where('id', $id)->first();
            $investor = User::select('fname')->where('id', $bid->investor_id)
                ->first()->fname;
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

    public function businessBids()
    {
        try {
            $bids = BusinessBids::with(['investor', 'listing'])
                ->where('owner_id', Auth::id())
                ->latest()->get();

            $result = $bids->filter(fn($bid) => $bid->investor && $bid->listing)
                ->map(function ($bid) {
                    $inv = $bid->investor;
                    return array_merge($bid->toArray(), [
                        'investor'        => $inv->fname . ' ' . $inv->lname,
                        'investor_name'   => trim($inv->fname . ' ' . $inv->mname . ' ' . $inv->lname),
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

    public function confirmedBids()
    {
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
                    'investor'        => $inv->fname . ' ' . $inv->lname,
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

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function agreeToProgressWithMilestone($bidId)
    {
        $user = Auth::user();
        $bidId = base64_decode($bidId);

        try {
            $progressVotingService = new AgreeToProgressVotingService($this->Client);

            $progressVotingService->ProcessBidsAndReleasePayment($bidId);

            return redirect()->to(config('app.app_url').'?agreetobid=yes');

        } catch (\Exception $e) {

            DB::rollBack();

            ErrorLogService::report($e, [
                'input' => request()->except(['password','token'])
            ]);

            return redirect()->to(config('app.app_url'));
        }
    }

    // Withdraw Accepted Investment
    public function withdraw_investment($bidId)
    {
        try {

            DB::beginTransaction();
            $bidService = new BusinessBidService($this->Client);

            $bid = AcceptedBids::with('milestone')->findOrFail($bidId);
            $investor = User::select('id','fname','lname','email')
                ->findOrFail($bid->investor_id);

            $business = Listing::findOrFail($bid->business_id);

            $owner = User::select('id','fname','lname','email')
                ->findOrFail($business->user_id);

            $investorName = $investor->fname.' '.$investor->lname;

            // Handle refund if monetary
            if ($bid->type === 'Monetary') {
                $bidService->handleWithdrawRefund($bid);
            }


            $business->update([
                'amount_collected' => $business->amount_collected - $bid->amount,
                'invest_count'     => $business->invest_count - 1
            ]);

            $bid->milestone->decrement('funding_collected', $bid->amount);


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
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function withdrawPendingInvestment($id)
    {
        try {
            $bid = BusinessBids::findOrfail($id);

            $owner = User::select('id','email')->findOrfail($bid->owner_id);
            $investor = User::select('fname','lname')->findOrfail($bid->investor_id);
            $invName = $investor->fname.' '.$investor->lname;

            // Refund / cleanup
            if ($bid->type === 'Monetary') {

                if ($bid->method === 'lipr') {
                    return response()->json(['message'=>'Lipr refund not available right now, please try later!'],500);
                }

                if ($bid->method === 'stripe') {
                    $this->Client->refunds->create(['charge'=>$bid->stripe_charge_id]);
                }

            } else {
                foreach (['legal_doc','optional_doc','photos'] as $f) {
                    if ($bid->$f && file_exists($bid->$f)) unlink($bid->$f);
                }
            }

            $listing = Listing::find($bid->business_id);

            $activeMilestone = $listing->milestones()
                ->where('progress_percentage','<',100)
                ->orderBy('id')->first();

            $bid->delete();

            // Funding update
            $listing->milestone->decrement('pending_collected',$bid->amount);

            // Threshold check
            if ($activeMilestone) {
                $bidService = new BusinessBidService($this->Client);
                $bidService->checkThreshold($listing, $bid);
            }

            // Notifications
            $this->notification->create(
                $owner->id, $bid->investor_id,
                'A bid to business '.$listing->name.' was cancelled by '.$invName,
                'investment-bids', 'business'
            );

            $this->notification->create(
                $bid->investor_id, $owner->id,
                'Your bid to business '.$listing->name.' was cancelled.',
                'investment-bids', 'business'
            );

            $this->emailService->send(
                'Bid Cancelled', 'bids.cancelled',
                ['business_name'=>$listing->name,'investor'=>$invName], $owner->email
            );

            return response()->json([
                'status'=>200,
                'message'=>'Bid removed & refund initiated!'
            ],200);

        } catch (\Exception $e) {

            ErrorLogService::report($e,['input'=>request()->except(['password','token'])]);

            return response()->json([
                'message'=>'Something went wrong, please try again later.'
            ],500);
        }
    }


    // optional from milestone done email click
    public function agreeToNextmile($bidId)
    {
        try {
            $bid = AcceptedBids::findOrFail($bidId);

            if ($bid->next_mile_agree) {
                return response()->json(['message' => 'Already agreed to next milestone.'], 409);
            }

            $bid->update(['next_mile_agree' => 1]);

            $listing = listing::findOrFail($bid->business_id);
            $investmentNeeded = $listing->investment_needed;

            $total_vote = AcceptedBids::where('business_id', $bid->business_id)
                ->where('ms_id', $bid->ms_id)
                ->where('next_mile_agree', 1)->get()
                ->sum(fn($b) => round(($b->amount / $investmentNeeded) * 10, 1) + ($b->project_manager ? 1 : 0));

            Milestones::where('listing_id', $bid->business_id)
                ->where('status', 'to_do')
                ->where('active', false)
                ->first()?->update(['active' => $total_vote >= 5.1]);

            return redirect()->to(config('app.app_url') . '?agreetonext=yes');

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
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
