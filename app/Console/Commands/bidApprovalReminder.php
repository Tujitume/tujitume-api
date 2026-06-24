<?php

namespace App\Console\Commands;

use App\Models\Auth\User;
use App\Models\Business\BusinessBids;
use App\Models\Business\Listing;
use App\Models\Communication\Notifications;
use Auth;
use DateTime;
use DB;
use Illuminate\Console\Command;
use Mail;
use Session;

class bidApprovalReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:bid_approval_reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This is a bid approval remider when business owner is inactive.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $bids = BusinessBids::get(); $cnt=0;
        $sent_businesses = array();
        foreach($bids as $bid)
        {
            $sent=0;
            $bid_placed = date( "Y-m-d H:i:s",strtotime($bid->created_at));
            $start_date = new DateTime(date("Y-m-d H:i:s"));
            $since_start = $start_date->diff(new DateTime($bid_placed));
            $time_past = $since_start->d.' days, '.$since_start->h.' hours, '. $since_start->i.' minutes';
            //echo $time_past.$bid->id; exit;

            $business = Listing::select('name')->where('id', $bid->business_id)->first();

            //Send Reminder Mail
            if(($since_start->d == 3 || $since_start->d == 7 ||
                $since_start->d == 14) && $since_start->h == 0)
            {
                foreach($sent_businesses as $s)
                if($s == $bid->business_id)
                    $sent++;

                if ($sent == 0) {
                    $owner = User::select('fname','email')
                    ->where('id',$bid->owner_id)->first();

                    $info=['business'=>$business->name,
                    'owner' => $owner->fname];
                    $user['to'] = $owner->email;

                    Mail::send('bids.reminder.bid_approve_reminder',
                    $info, function($msg)
                    use ($user){
                    $msg->to($user['to']);
                    $msg->subject('Bid Approval Reminder');
                    });
                    $sent_businesses[] = $bid->business_id;
                }

                //High value alert
                if($bid->amount > 20000){
                    //... Logic
                }
            }

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
    }


}
