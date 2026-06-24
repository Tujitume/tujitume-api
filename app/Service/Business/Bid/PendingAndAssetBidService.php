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
use Illuminate\Support\Facades\Mail;
use Stripe\StripeClient;

class PendingAndAssetBidService
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

    /* Pending Bid Service */

    public function processBidCancellation($bid, $business, $owner, $investor, $investorName)
    {
        Mail::send('bids.cancelled', [
            'investor' => $investorName,
            'type' => $bid->type,
            'business_name' => $business->name
        ], function ($msg) use ($owner) {
            $msg->to($owner->email);
            $msg->subject('Bid Cancel');
        });

        $this->notification->create(
            $owner->id,
            $bid->investor_id,
            'A bid to business '.$business->name.' was cancelled by '.$investorName,
            '/',
            'business'
        );

        $this->notification->create(
            $bid->investor_id,
            $owner->id,
            'Your bid to business '.$business->name.' was cancelled.',
            '/my-investments',
            'business'
        );

        $bid->delete();

        if ($bid->status === 'verified') {

            $business->decrement('amount_collected', $bid->amount);
            $business->decrement('invest_count', 1);

            $business->milestone->decrement('funding_collected', $bid->amount);
        }
    }

    public function sendCancelConfirmation($bid, $investor, $business, $owner, $investorName)
    {
        $info = [
            'inv_name' => $investorName,
            'type' => $bid->type,
            'bid_id' => base64_encode($bid->id),
            'asset_name' => $bid->serial,
            'business_name' => $business->name
        ];

        Mail::send('bids.cancel_confirm', $info, function ($msg) use ($investor) {
            $msg->to($investor->email);
            $msg->subject('Bid Cancel Confirmation');
        });

        $text = 'Your bid to business '.$business->name.' will be cancelled.';

        $this->notification->createWithBidId(
            $bid->investor_id,
            $owner->id,
            $bid->id,
            $text,
            'bid_cancel_confirm',
            'business'
        );
    }

    /* Asset Bid Service */



}
