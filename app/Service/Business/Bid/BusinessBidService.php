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

            // The deposit is on its way back: the bid goes now, together with its pending amount, so a retry can never
            // refund it twice (this used to fail after the refund on a missing pending_collected() method)
            $milestone = $bid->milestone;
            \Illuminate\Support\Facades\DB::transaction(function () use ($bid, $milestone) {
                if ($milestone && (float) $milestone->pending_collected >= (float) $bid->amount) {
                    $milestone->decrement('pending_collected', $bid->amount);
                }
                $bid->delete();
            });

            $listing->refresh();
            if ($milestone) {
                $milestone->refresh();
                if (((float) $milestone->pending_collected + (float) $milestone->funding_collected) < (float) $milestone->amount) {
                    $listing->update(['threshold_met' => 0]);
                    $text = 'Business '.$listing->name.' is yet to meet the threshold due to bid rejection';
                    foreach ($listing->bids()->pluck('investor_id')->unique() as $invId) {
                        $this->notification->create($invId, $bid->owner_id, $text, 'my-investments', 'business');
                    }
                    $this->notification->create($bid->owner_id, null, $text, 'investment-bids', 'business');
                }
            }

            $this->emailService->send(
                'Bid Rejected',
                'bids.rejected',
                ['business_name' => $listing->name],
                $bid->investor->email
            );

            $this->notification->create(
                $bid->investor_id,
                $bid->owner_id,
                'Your bid to '.$listing->name.' was not accepted. Any deposit you paid has been refunded to your wallet.',
                'my-investments',
                'business'
            );
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
                // the bid's equity is split with its money when part of it spills into the next milestone (both rows used
                // to carry the whole share, so the equity counted twice)
                'representation' => $spillAmount && $nextMs && (float) $bid->amount > 0
                    ? round((float) $bid->representation * $bidAmount / (float) $bid->amount, 2)
                    : $bid->representation,
                'serial' => $bid->serial,
                'legal_doc' => $bid->legal_doc,
                'optional_doc' => $bid->optional_doc,
                'photos' => $bid->photos,
                'stripe_charge_id' => $bid->stripe_charge_id,
                'status' => $status
            ]);

            // (An accepted bid stays awaiting_payment / under_verification until the investor pays the 75% or the
            // asset is verified; it used to be confirmed and "paid in full" here on every accept.)

            if ($spillAmount && $nextMs) {

                $accepted->replicate()
                    ->fill([
                        'amount' => $spillAmount,
                        'ms_id' => $nextMs->id,
                        'representation' => round((float) $bid->representation - (float) $accepted->representation, 2),
                    ])->save();

                if ($bid->type === 'Monetary') {
                    $nextMs->increment('funding_collected', $spillAmount);
                }
            }

            if ($bid->type === 'Monetary') {

                $listing->increment('amount_collected', $bid->amount);

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

            // The whole bid was counted as pending on the milestone it was placed on (status_bids): it leaves there in
            // full. Taking only the in-milestone part off the milestone funded now left money pending on the old one and
            // a negative pending on the next.
            $pendingMs = $bid->ms_id ? $milestones->firstWhere('id', $bid->ms_id) : $activeMs;
            ($pendingMs ?? $activeMs)->decrement('pending_collected', min((float) $bid->amount, (float) (($pendingMs ?? $activeMs)->pending_collected)));

            $bid->delete();

            $this->refreshThreshold($listing);
            $listing->refreshInvestorCount(); // distinct investors (this was +1 per accepted bid)
        }

        return response()->json(['message' => 'Accepted!'], 200);
    }

    /**
     * threshold_met is about the milestone now raising money, not the business: once a milestone's money is all
     * accepted, the next milestone has to meet its own target before its bids can be accepted. (It was set once, on the
     * first milestone, and never cleared, so every later bid was "ready to accept" straight away and the deal room
     * said "Target met".) Accepted assets still being verified count as committed.
     */
    private function refreshThreshold($listing): void
    {
        // Committed money (accepted rows + bids still pending, wherever they were placed) against the targets of every
        // milestone up to the first one not yet covered by accepted money
        $milestones = $listing->milestones()->orderBy('id')->get();
        $accepted = AcceptedBids::where('business_id', $listing->id)->get(['ms_id', 'amount']);
        $target = 0.0; $next = null;
        foreach ($milestones as $m) {
            $target += (float) $m->amount;
            if ((float) $accepted->where('ms_id', $m->id)->sum('amount') < (float) $m->amount) { $next = $m; break; }
        }
        $committed = (float) $accepted->sum('amount') + (float) $milestones->sum('pending_collected');
        $listing->update(['threshold_met' => !$next || $committed >= $target ? 1 : 0]);
    }

    // Withdraw investment methods
    //----------------------------------------
    public function handleWithdrawRefund($bid)
    {
        if ($bid->method === 'lipr') {
            // Paid by M-Pesa into the platform wallet: refund the part paid so far (25% before the remaining 75% is
            // paid, all of it after), less the same 2% kept on card refunds, to the investor's LIPR wallet
            $investor = \App\Models\Auth\User::find($bid->investor_id);
            $platformWallet = \App\Models\Misc\Setting::where('key', 'platform_lipr_wallet')->value('value');
            if (!$investor?->lipr_wallet) {
                throw new \Exception('Set up your wallet first, so your investment can be refunded to it.', 422);
            }
            $paidShare = strtolower((string) $bid->status) === 'confirmed' ? 1.0 : 0.25;
            // A business's money (bids included) is in KSh, as the business form and checkout show it
            $refundKes = (int) round((float) $bid->amount * $paidShare * 0.98);
            if (!$platformWallet || $refundKes <= 0) {
                throw new \Exception('The refund could not be worked out. Please contact support.', 422);
            }
            $refund = app(\App\Service\LiprMpesa\LiprW2W::class)->send($refundKes, $investor->lipr_wallet, $platformWallet, null, [
                'narration' => 'Investment refund', 'listing_id' => $bid->id,
            ]);
            if (empty($refund['success'])) {
                throw new \Exception('The refund could not be sent right now; your investment is unchanged. Please try again.', 502);
            }
            (new \App\Service\Misc\TransactionRecord())->create($investor->id, 'refund', 'lipr', $refundKes, $refund['reference'] ?? null, $investor->id);
            return;
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
            // The owner turned the bid down: the investor's whole M-Pesa deposit goes back to their LIPR wallet
            $payment = \App\Models\Finance\LiprPayment::where('reference_id', $bid->lipr_transaction_id)->first();
            $refundKes = (int) round((float) ($payment->amount ?? 0));
            $investor = \App\Models\Auth\User::find($bid->investor_id);
            $platformWallet = \App\Models\Misc\Setting::where('key', 'platform_lipr_wallet')->value('value');
            if ($refundKes <= 0 || !$platformWallet) {
                throw new \Exception('The deposit refund could not be worked out. Please contact support.', 422);
            }
            if (!$investor?->lipr_wallet) {
                throw new \Exception('The investor has no wallet to refund the deposit to yet.', 422);
            }
            $refund = app(\App\Service\LiprMpesa\LiprW2W::class)->send($refundKes, $investor->lipr_wallet, $platformWallet, null, [
                'narration' => 'Bid deposit refund', 'listing_id' => $bid->id,
            ]);
            if (empty($refund['success'])) {
                throw new \Exception('The deposit refund could not be sent right now; the bid is unchanged. Please try again.', 502);
            }
            (new \App\Service\Misc\TransactionRecord())->create($investor->id, 'refund', 'lipr', $refundKes, $refund['reference'] ?? null, $investor->id);
            return;
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
