<?php

namespace App\Console\Commands;

use App\Models\Auth\User;
use App\Models\Milestones\Milestones;
use Auth;
use DateTime;
use DB;
use Hash;
use Illuminate\Console\Command;
use Mail;
use Response;
use Session;

class sendBmilestoneReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:old_milestone_reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This is a milestone due reminder';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $milestones = Milestones::get(); $c=0;$d=0;

          foreach($milestones as $mile){
          if($mile->status == 'In Progress') {

            $time_due_date = date( "Y-m-d H:i:s", strtotime($mile->created_at.' +'.$mile->n_o_days.' days 0 hours 0 minutes'));
            $start_date = new DateTime(date("Y-m-d H:i:s"));
            $since_start = $start_date->diff(new DateTime($time_due_date));

            $time_left = $since_start->d.' days, '.$since_start->h.' hours, '. $since_start->i.' minutes';

            //Send Mail
            $business_owner = User::where('id',$mile->user_id)->first();
            if($business_owner && $business_owner->email)
                $toMail = $business_owner->email;
            else
                $toMail = '';

              if($since_start->invert == 0)
              {
                  if($since_start->d == 1 && $since_start->h == 23){
                      $info=['d'=>1, 'name'=>$mile->title,'amount'=>$mile->amount,];
                      $user['to'] = $toMail; //['sohaankane@gmail.com'];

                      Mail::send('milestone.milestone_due_mail', $info, function($msg) use ($user){
                          $msg->to($user['to']);
                          $msg->subject('Milestone Due');
                      });
                  }

                  else if($since_start->d == 0 && $since_start->h == 23){
                      $info=['d'=>0, 'name'=>$mile->title,'amount'=>$mile->amount,];
                      $user['to'] = $toMail; //['sohaankane@gmail.com'];

                      Mail::send('milestone.milestone_due_mail', $info, function($msg) use ($user){
                          $msg->to($user['to']);
                          $msg->subject('Milestone Due Today');
                      });
                  }
              }

          }
      }

    }

}
