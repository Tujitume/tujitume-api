<?php
namespace App\Service\Business\Bid;

use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\Listing;
use App\Models\Milestones\Milestones;
use App\Models\Misc\Setting;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\Business\Auth;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\TransactionRecord;
use App\Service\Notification\EmailService;
use App\Service\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

class AgreeToProgressVotingService
{
    public function __construct(StripeClient $client)
    {
        $this->Client = $client;
        $this->emailService = new EmailService();
        $this->notification = new NotificationService();
        $this->balance = new BalanceService();
        $this->liprW2W = new LiprW2W();
        $this->transaction = new TransactionRecord();
    }
    // Agree to proceed with next milestone
    public function ProcessBidsAndReleasePayment(int $bidId): void
    {
        DB::transaction(function () use ($bidId) {
            $bid = AcceptedBids::findOrFail($bidId);

            $bid->update(['investor_agree' => 1]);

            $listing = Listing::select('id','name','user_id','investment_needed')
                ->findOrFail($bid->business_id);

            $owner = User::select('id','connect_id','lipr_wallet','email')
                ->findOrFail($listing->user_id);

            $bids = AcceptedBids::where('business_id', $bid->business_id)
                ->where('ms_id', $bid->ms_id)
                ->where('investor_agree', 1)
                ->get();

            /*
            |--------------------------------------------------------------------------
            | Calculate Voting Rating
            |--------------------------------------------------------------------------
            */

            $totalRating = $bids->sum(function ($b) use ($listing) {

                $rating = round(($b->amount / $listing->investment_needed) * 10, 2);

                if ($b->project_manager) {
                    $rating += 1;
                }

                return $rating;
            });

            /*
            |--------------------------------------------------------------------------
            | Release Eligible Bids
            |--------------------------------------------------------------------------
            */

            if ($totalRating >= 5.10) {

                $releaseBids = $bids
                    ->where('type', 'Monetary')
                    ->where('payment_released', 0);

                foreach ($releaseBids as $b) {

                    if ($b->method === 'stripe') {

                        if (!$owner->connect_id) {
                            throw new \Exception('Payment Release Failed: Business owner not onboarded to Stripe.');
                        }

                        /*
                        $transfer = $this->Client->transfers->create ([
                            //"billing_address_collection": null,
                            "amount" => $releasing['amount']*100, //100*100,
                            "currency" => 'USD',
                            "source_transaction" => $releasing['charge_id'],
                            'destination' => $owner->connect_id
                        ]);

                        if(!$transfer){
                            throw new \Exception('Payment Release Failed: Transfer failed by stripe.', 400);
                        }
                        */

                        $this->transaction->create(
                            $b->investor_id, 'business_milestone', 'stripe', $b->amount, null, $owner->id
                        );
                    }

                    elseif ($b->method === 'lipr') {

                        if (!$owner->lipr_wallet) {
                            throw new \Exception('Milestone release failed: Business owner not onboarded to Lipr.');
                        }

                        /*
                        $amountKes = round($usdToKes*$transferAmount, 2);
                        $transfer = $this->liprW2W->send($amountKes, $owner->id);

                        if(!$transfer['success']){
                            throw new \Exception("Lipr transfer failed: ".$transfer['errors'][0], 422);
                        }

                        # Update Wallet
                        $this->balance->updateBalance($owner->id, (float)$releasing['amount'], $releasing['method']);
                        AcceptedBids::where('id',$releasing['bid_id'])
                            ->update(['payment_released' => 1]);
                        */

                        $this->transaction->create(
                            $bid->investor_id,'business_milestone','lipr', $b->amount, 'N/A', $owner->id
                        );
                    }

                    $b->update(['payment_released' => 1]);
                }

                $milestoneTitle = Milestones::where('id',$bid->ms_id)->value('title');

                $this->notification->createWithBidId(
                    $bid->owner_id, $bid->investor_id, $bidId,
                    'All payments for Milestone '.$milestoneTitle.' of Business '.$listing->name.' are now released!',
                    '/', 'investor'
                );

                // Email Notification
            }

        });

    }

    // Asset voting logic & Payment Release check
    public function agreeToReleaseAssetAndMonetaryBid($bidId)
    {
        $bidId = base64_decode($bidId);

        try {
            $bid = AcceptedBids::findOrFail($bidId);

            if ($bid->status === 'equipment_released') {
                return response()->json(['message' => 'Equipment already released for this bid.'], 409);
            }
            // Update agreement status
            $bid->update(['investor_agree' => 1]);

            $milestone = Milestones::findOrFail($bid->ms_id);
            $listing = $milestone->listing; $owner = $listing->owner;

            $investor_agree = AcceptedBids::where('business_id',$bid->business_id)
                ->where('ms_id',$bid->ms_id)
                ->where('investor_agree',1)->get();

            $t_rating = 0;
            $release = [];

            foreach ($investor_agree as $aBid) {
                $rating = round(($aBid->amount / $milestone->amount) * 10, 2);
                if ($aBid->project_manager) $rating += 1;
                $t_rating += $rating;

                $release[] = [
                    'amount' => $aBid->amount,
                    'type' => $aBid->type,
                    'stripe_charge_id' => $aBid->stripe_charge_id
                ];
            }

            // Release payments if rating threshold met
            if ($t_rating >= 5.10) {
                foreach ($release as $r) {
                    if ($r['type'] === 'Monetary') {
                        $this->Client->transfers->create([
                            'amount' => $r['amount'] * 100,
                            'currency' => 'USD',
                            'destination' => $owner->connect_id
                        ]);
                    }
                }

                $this->notification->createWithBidId(
                    $bid->owner_id, $bid->investor_id, $bidId,
                    'All payments for Milestone '.$milestone->title.' of Business '.$listing->name.' are now released!',
                    '/', 'investor'
                );
            }

            return $t_rating;

        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

}
