<?php
namespace App\Service\Business\Bid;

use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Service\Notification\EmailService;
use App\Service\Notification\NotificationService;
use Illuminate\Support\Facades\Auth;
use Stripe\StripeClient;

class BusinessBidService
{
    public function __construct(StripeClient $client)
    {
        $this->Client = $client;
        $this->emailService = new EmailService();
        $this->notification = new NotificationService();
    }
    public function rejectBids(array $bidIds)
    {
        foreach ($bidIds as $id) {

            $bid = BusinessBids::with(['investor','listing.owner','milestone'])->find($id);
            if (!$bid) continue;

            $listing = $bid->listing;
            if ($listing->owner->id !== Auth::id()) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $this->refundBidIfNeeded($bid);

            $milestone = $bid->milestone;
            if ($milestone) {
                $milestone->decrement('pending_collected', $bid->amount);
            }

            $listing->refresh();

            $totalPending  = $milestone->pending_collected();
            $totalAccepted = $milestone->funding_collected();

            if (($totalPending + $totalAccepted) < $milestone->amount) {

                $listing->update(['threshold_met' => 0]);
                $text = 'Business '.$listing->name.' is yet to meet the threshold due to bid rejection';

                $investors = $listing->bids()->pluck('investor_id');

                foreach ($investors as $invId) {
                    $this->notification->create($invId, $bid->business_id, $text, '/', 'business');
                }

                $this->notification->create($bid->owner_id, $bid->business_id, $text, '/', 'business');
            }

            $this->emailService->send(
                'Bid Rejected',
                'bids.rejected',
                ['business_name' => $listing->name],
                $bid->investor->email
            );

            $this->notification->create(
                $bid->investor_id,
                $bid->business_id,
                'Sorry your bid to '.$listing->name.' has been rejected, please try again properly!',
                '/',
                'business'
            );

            $bid->delete();
        }

        return response()->json(['message' => 'Rejected!'], 200);
    }

    public function acceptBids(array $bidIds)
    {
        foreach ($bidIds as $id) {

            $bid = BusinessBids::with(['listing.owner','listing.milestones','investor'])->find($id);
            if (!$bid) continue;

            $listing = $bid->listing;
            if ($listing->owner->id !== Auth::id()) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            if (!$listing->threshold_met) {
                return response()->json(['message' => 'This business is yet to meet the threshold'],422);
            }

            $amountNeeded = $listing->investment_needed - $listing->amount_collected;
            if ($bid->amount > $amountNeeded) {
                return response()->json([
                    'message' => 'Selected total amount exceeds the amount needed for this business'
                ],422);
            }

            $milestones = $listing->milestones()->orderBy('id')->get();

            [$activeMs, $bidAmount, $spillAmount, $nextMs] =
                $this->resolveMilestoneFunding($milestones, $bid->amount);

            if (!$activeMs) {
                return response()->json(['message' => 'No active milestone found.'],400);
            }

            $status = $bid->type === 'Monetary'
                ? 'awaiting_payment'
                : 'under_verification';

            $accepted = AcceptedBids::create([
                'bid_id' => $bid->id,
                'ms_id' => $activeMs->id,
                'date' => $bid->date,
                'investor_id' => $bid->investor_id,
                'business_id' => $bid->business_id,
                'owner_id' => $bid->owner_id,
                'type' => $bid->type,
                'method' => $bid->method,
                'amount' => $bidAmount,
                'representation' => $bid->representation,
                'serial' => $bid->serial,
                'legal_doc' => $bid->legal_doc,
                'optional_doc' => $bid->optional_doc,
                'photos' => $bid->photos,
                'stripe_charge_id' => $bid->stripe_charge_id,
                'status' => $status
            ]);

            if ($listing->owner->id === Auth::id()) {
                $accepted->update([
                    'status' => 'confirmed',
                    'paid_in_full' => true
                ]);
            }

            if ($spillAmount && $nextMs) {

                $accepted->replicate()
                    ->fill([
                        'amount' => $spillAmount,
                        'ms_id' => $nextMs->id
                    ])->save();

                if ($bid->type === 'Monetary') {
                    $nextMs->increment('funding_collected', $spillAmount);
                }
            }

            if ($bid->type === 'Monetary') {

                $listing->increment('amount_collected', $bid->amount);
                $listing->increment('invest_count');

                $activeMs->increment('funding_collected', $bidAmount);
            }

            $this->emailService->send(
                'Bid Accepted',
                'bids.initially_accepted',
                [
                    'business_name'=>$listing->name,
                    'bid_id'=> base64_encode($accepted->id),
                    'original_bid_id'=> base64_encode($bid->id),
                    'type'=> $bid->type,
                    'amount'=> base64_encode($bid->amount),
                    'id'=> $listing->id
                ],
                $bid->investor->email
            );

            $this->notification->create(
                $bid->investor_id,
                $bid->owner_id,
                'Your bid to '.$listing->name.' has been accepted!',
                'my-investments',
                'business'
            );

            $activeMs->decrement('pending_collected', $bidAmount);

            $bid->delete();
        }

        return response()->json(['message' => 'Accepted!'], 200);
    }

    // Withdraw investment methods
    //----------------------------------------
    public function handleWithdrawRefund($bid)
    {
        if ($bid->method === 'lipr') {
            throw new \Exception('Lipr refund not available right now.');
        }

        if ($bid->status === 'awaiting_payment') {

            $refundAmount = ($bid->amount * 0.25) * 0.98;

            $this->Client->refunds->create([
                'charge' => $bid->stripe_charge_id,
                'amount' => $refundAmount * 100
            ]);
        }

        elseif ($bid->status === 'confirmed') {

            $chargeIds = explode(',', $bid->stripe_charge_id);

            $refund25 = ($bid->amount * 0.25) * 0.98;
            $refund75 = ($bid->amount * 0.75) * 0.98;

            $this->Client->refunds->create([
                'charge' => $chargeIds[0],
                'amount' => $refund25 * 100
            ]);

            $this->Client->refunds->create([
                'charge' => $chargeIds[1],
                'amount' => $refund75 * 100
            ]);
        }
    }

    public function checkThreshold($business, $bid)
    {
        $milestone = $bid->milestone;

        if (!$milestone) {
            return false;
        }

        $totalBidAmount =
            $business->bids()->sum('amount') +
            $business->accepted_bids()->sum('amount');

        if ($totalBidAmount < $milestone->amount) {

            $business->update(['threshold_met' => 0]);

            $this->notification->create(
                $business->user_id,
                $bid->investor_id,
                'A Business '.$business->name.' is yet to meet the threshold',
                'investment-bids',
                'business'
            );

            return false;
        }

        return true;
    }

    # Helper methods for bid processing

    private function refundBidIfNeeded($bid)
    {
        if ($bid->type !== 'Monetary') return;

        if ($bid->method === 'stripe' && $bid->stripe_charge_id) {

            $paidCents = (int) round($bid->amount * 0.25 * 100);
            $refundCents = (int) round($paidCents * 0.98);

            $this->Client->refunds->create([
                'charge' => $bid->stripe_charge_id,
                'amount' => $refundCents
            ]);
        }

        if ($bid->method === 'lipr') {
            throw new \Exception('Lipr refund not supported yet');
        }
    }

    private function resolveMilestoneFunding($milestones, $amount)
    {
        $spillAmount = 0;
        $nextMs = null;
        $activeMs = null;
        $bidAmount = $amount;

        foreach ($milestones as $index => $ms) {

            if ($ms->funding_collected < $ms->amount) {

                $activeMs = $ms;

                $total = $ms->funding_collected + $amount;

                if ($total > $ms->amount) {

                    $spillAmount = $total - $ms->amount;
                    $bidAmount = $amount - $spillAmount;
                    $nextMs = $milestones->get($index + 1);
                }

                break;
            }
        }

        return [$activeMs, $bidAmount, $spillAmount, $nextMs];
    }

}
