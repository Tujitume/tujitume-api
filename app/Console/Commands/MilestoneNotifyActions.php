<?php

namespace App\Console\Commands;


use App\Http\Controllers\Business\MidMilestoneController;
use App\Http\Controllers\Business\MilestoneController;
use App\Models\Milestones\MidMilestone;
use App\Models\Milestones\MilestoneExecutionDocuments;
use App\Models\Milestones\MilestonePreReleaseRequest;
use App\Models\Milestones\Milestones;
use App\Models\Milestones\NonCompliance\MilestoneNonCompliance;
use App\Service\Business\Milestone\MilestoneFundReleaseService;
use App\Service\Misc\ErrorLogService;
use App\Service\Noncompliance\NonCompliantService;
use App\Service\Notification\EmailService;
use App\Service\Notification\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Mockery\Exception;
use PHPUnit\Event\Code\Throwable;
use Stripe\StripeClient;

class MilestoneNotifyActions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:milestone_reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Milestone actions & notifies both investors and SMEs about milestone progress.';

    /**
     * Execute the console command.
     */
    public function handle(EmailService $emailService)
    {
        //        PENDING MONEY  → decides viability & risk
        //        REAL MONEY     → decides execution & audits
        //        NEVER MIX THEM

        $emailService = new EmailService();
        $notification = new NotificationService();
        // Get milestones in sequential order
        try{
            $milestones = Milestones::orderBy('id')->get();

            # Loop over each milestone
            foreach ($milestones as $index => $milestone) {

                // --- Funding Calculation --- // PENDING vs REAL
                if ($milestone->listing->threshold_met) {
                    $funds_collected = $milestone->funding_collected;
                }
                else{
                    $funds_collected = $milestone->pending_collected;
                }

                $milestone->progress_percentage = $milestone->amount
                    ? round(($funds_collected / $milestone->amount) * 100)
                    : 0;

                # Termination Conditions
                if (in_array($milestone->status, [
                    'done',
//                    'in_mid_audit',
//                    'in_pr_audit',
//                    'in_final_audit',
//                    'rmep_submitted',
//                    'mid_milestone_submitted',
                    'admin_review',
                    'execution_submitted',
                ])) {
                    $milestone->save();
                    continue;
                }

                // --- Date Calculations ---
                $now = Carbon::now(); $today = Carbon::today();
                $deadline = Carbon::parse($milestone->deadline_date);
                $exec_deadline = Carbon::parse($milestone->exec_deadline_date);

                // Exact Due In Time
                $diffTime = $now->diff(Carbon::parse($deadline), false);
                $diffTimeForExec = $now->diff(Carbon::parse($exec_deadline), false);

                $days = $diffTime->days; $hours = $diffTime->h;
                $minutes = $diffTime->i;
                $isOverdue = $diffTime->invert === 1; // 1 = past, 0 = upcoming
                $isExecOverdue = $diffTimeForExec->invert === 1;

                //Mid-Milestone Request, after 30% timeline passed of exec
                $daysToExec = $milestone->no_o_days;
                $totalHours = max(1, $daysToExec * 24);//$milestone->no_o_days * 24;
                $hoursRemaining = $now->diffInHours($exec_deadline, false);
                $hoursPassed = max(0,$totalHours - $hoursRemaining);
                $has30PercentPassed = !$isExecOverdue && ($hoursPassed / $totalHours) >= 0.3;

                if (!$milestone->exec_deadline_date || !$milestone->fund_released_75) {
                    $has30PercentPassed = false;
                }

                $owner = $milestone->listing->owner;

                // Mid-Milestone Verification Request
                if ($milestone->fund_released_75 && $has30PercentPassed && !$milestone->mid_milestone_started) {
                    // Send notification / email to BO
                    $text = 'Milestone '. $milestone->title. ' is required mid-milestone verification, please upload necessary mid-milestone documents.';
                    $notification->create(
                        $owner->id, null, $text, 'milestones', 'milestone'
                    );

                    // Email
                    $subject = 'Milestone Approved'; $mail_to = $owner->email;;
                    $data = [
                        'boName' => $owner->fname,
                        'milestoneName' => $milestone->title,
                        'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
                    ];
                    $emailService->send('Mid Milestone Required', 'milestone.mid_milestone.system_request', $data, $mail_to);

                    // Mark as notified
                    $milestone->mid_milestone_started = true;
                    $milestone->save();
                }

                // Email 1 — 7 Days Before Deadline - Business Owner Reminders
                if(!$isOverdue) {
                    $mail_to = $milestone->listing->owner->email;
                    $listing_id = base64_encode(base64_encode($milestone->listing->id));
                    //

                    // 1 Day before
                    echo " //M". $milestone->id. "- $days days $hours h $minutes m".$milestone->listing->id. ' New/ ';
                    if($days == 0 && $hours == 24){
                        $subject = 'Milestone Due Today';
                        $data=[
                            'milestone_name'=>$milestone->title,
                            'funding_bar_html'=> $milestone->progress_percentage,
                            'funding_link'=> 'https://beta.tujitume.com/business-milestones/'. $listing_id,
                            //'status'=>$milestone->status
                        ];
                        $emailService->send($subject, 'milestone.reminders.1_day', $data, $mail_to);

                    }
                    // Email 2 — At risk | 2 Days Before Deadline AND <60%
                    if ($days === 2 && $hours == 0 && $milestone->progress_percentage < 60) {
                        $subject = 'Milestone At Risk';
                        $data=[
                            'milestone_name'=>$milestone->title,
                            'invest_link'=>'https://beta.tujitume.com/business-milestones/'. $listing_id,
                            'current_funding'=>$milestone->pending_collected,
                            'funding_goal'=>$milestone->amount,
                            'days_left'=>$days
                        ];
                        $emailService->send($subject, 'milestone.reminders.2_days', $data, $mail_to);
                    }

                    //7 Days before
                    if ($days === 6 && $hours == 23) {
                        $subject = 'Milestone Due In A Week';
                        $data=[
                            'milestone_name'=>$milestone->title,
                            'invest_link'=>'https://beta.tujitume.com/business-milestones/'. $listing_id,
                            'current_funding'=>$milestone->pending_collected,
                            'funding_goal'=>$milestone->amount,
                            'days_left'=>$days
                        ];
                        $emailService->send($subject, 'milestone.reminders.7_days', $data, $mail_to);
                    }


                    // Email 4 — Continuation Vote (60–99%)
                    if ($milestone->progress_percentage >= 60 && $milestone->progress_percentage < 100
                        && $days === 0 && $hours == 0 && $milestone->status !== 'continuation_triggered'
                    ) {
                        $subject = 'Milestone Continuation Triggered';
                        $data=[
                            'milestone_name'=>$milestone->title,
                            'stay_link'=>'https://beta.tujitume.com/dashboard/dealroom',
                            'refund_link'=>'https://beta.tujitume.com/dashboard/dealroom',
                            'amount_raised'=>$milestone->pending_collected,
                            'funding_goal'=>$milestone->amount,
                            'days_left'=>$days
                        ];
                        $emailService->send($subject, 'milestone.reminders.continuation_vote', $data, $mail_to);
                    }

                    // Email 5 — Milestone Fully Funded
                    if ( !$milestone->is_funded &&$days === 0 && $milestone->progress_percentage >= 100 && $milestone->status !== 'done') {
                        $subject = 'Milestone Is Funded';
                        $data=[
                            'milestone_name'=>$milestone->title,
                            'execution_link'=>'https://beta.tujitume.com/business-milestones/'. $listing_id,
                            'total_raised'=>$milestone->pending_collected,
                        ];
                        $emailService->send($subject, 'milestone.reminders.milestone_funded', $data, $mail_to);
                    }

                }
                // Non-Compliant / Overdue
                elseif($isOverdue && $milestone->progress_percentage < 60
                    && ($milestone->status != 'non_compliant' && $milestone->status != 'locked')){
                    $ncService = new NonCompliantService();
                    $ncService->trigger($milestone);

                   //Can also trigger if
                       //Business Owner Inactivity
                       //Investor Complaints of No Progress

                }

            }

            $this->PreReleaseAutoApprovePending();
            $this->midMilestoneAutoApprovePending();
            $this->FinalAutoApprovePending();

            // Handle Non-Compliance's
            $this->resolveNonCompliances();
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            echo  $e->getMessage(); exit;
        }

    }

    public function PreReleaseAutoApprovePending()
    {
        $notification = new NotificationService();
        $pending = MilestonePreReleaseRequest::with('milestone')->where('status', 'submitted')->get();

        foreach ($pending as $req) {

            $submittedAt = $req->updated_at;
            $days = now()->diffInDays($submittedAt);

            // Should see the vote results after 10 days, before auto approve

            if ($days >= 10) {
                // Auto approve
                $req->status = 'approved';
                $req->milestone->increment('pr_approved', 1);
                $req->save(); $req->milestone->save();

                // Notify BO
//                $text = 'Milestone '. $req->milestone->title. ' has been approved by '.$investor->fname.' '.$investor->lname ;
//                $notification->create(
//                    $req->milestone->listing->user_id, null, $text, 'milestones', 'milestone'
//                );

                //Evaluate if fully approved
                $total_votes = ($req->milestone->pr_approved + $req->milestone->pr_rejected + $req->milestone->pr_audit);
                $uniqueInvestors = $req->milestone->investors()->distinct('users.id')->count('users.id');

                // Check if all voted
                if($total_votes >= $uniqueInvestors && !$req->milestone->fund_released_75 && $req->milestone->status != 'in_pr_audit'){
                    $milestone = $req->milestone;
                    $approved_ratio  = $total_votes > 0 ? ($milestone->pr_approved / $total_votes) : 0 ;

                    // threshold: 51%
                    if($approved_ratio  >= 0.51){
                        $milestoneController = new MilestoneController();

                        $fundReleaseService = new MilestoneFundReleaseService(new StripeClient());

                        $result = $fundReleaseService->release($req->milestone, 75);

                        if (!$result->success) {
                            ErrorLogService::report(throw New Exception($result->message, 422), [$result->message]);
                        }

                        $daysToExec = $req->milestone->no_o_days;
                        $req->milestone->fund_released_75 = true;
                        $req->milestone->status = 'in_progress';

                        $old = Carbon::parse($req->milestone->exec_deadline_date);
                        $req->milestone->exec_deadline_date = now()->addDays($daysToExec)->format('Y-m-d');

                        $req->milestone->save();
                        $req->save();
                        $milestoneController->notify($req->milestone->listing->owner, 'pr_approved', $req->milestone);
                    }
                }
            }


        }

    }


    public function midMilestoneAutoApprovePending()
    {
        $notification = new NotificationService();
        $pending = MidMilestone::with('milestone')->where('status', 'submitted')->get();

        foreach ($pending as $mid) {


            $submittedAt = $mid->updated_at;
            $days = now()->diffInDays($submittedAt);

            if ($days >= 10) {
                // Auto approve
                $milestoneController = new MidMilestoneController();
                $mid->increment('approve_count', 1);
                $uniqueInvestors = $mid->milestone->investors()->distinct('users.id')->count('users.id');
                $total_votes = $mid->approve_count + $mid->reject_count + $mid->pm_audit_count;

                // Check if all voted
                if($total_votes >= $uniqueInvestors && !$mid->milestone->fund_released){
                    $approved_ratio  = $total_votes > 0 ? ($mid->approve_count / $total_votes) : 0 ;
                    // threshold: 51%
                    if ($approved_ratio >= 0.51) {
                        if($mid->milestone->fund_released_75){
                            $mid->status = 'approved';
                            $mid->save();

                            $fundReleaseService = new MilestoneFundReleaseService(new StripeClient());

                            $result = $fundReleaseService->release($mid->milestone, 25);

                            if (!$result->success) {
                                ErrorLogService::report(throw New Exception($result->message, 422), [$result->message]);
                            }

                            $milestoneController->notify($mid->milestone->listing->owner, 'approved', $mid);
                        }
                    }
                }
            }
        }

    }

    public function FinalAutoApprovePending()
    {
        $notification = new NotificationService();
        $pendingExec = MilestoneExecutionDocuments::with('milestone')->where('status', 'submitted')->get();

        foreach ($pendingExec as $req) {

            $submittedAt = $req->updated_at;
            $days = now()->diffInDays($submittedAt);

            if ($days >= 10) {
                // Auto approve
                $req->status = 'approved';
                $req->increment('approve_count', 1);
                $req->save();

                //Evaluate if fully approved
                $milestone = $req->milestone;

                $total_votes = $req->approve_count + $req->reject_count + $req->audit_count;
                $approved_ratio  = $total_votes > 0 ? ($req->approve_count / $total_votes) : 0 ;

                // threshold: 51%
                $client = new StripeClient();

                if($approved_ratio  >= 0.51){
                    $milestoneController = new MilestoneController($client);
                    if($milestone->fund_released){
                        $req->status = 'approved'; $req->save();
                        $milestone->status = 'done'; $milestone->save();
                        $milestoneController->notify($milestone->listing->owner, 'final_approved', $milestone);
                    }
                }
            }
        }

    }

    // Non Compliant / Overdue
    public function resolveNonCompliances()
    {
        $ncService = new NonCompliantService();

        // Stage -2 | Response Window
        $ncs = MilestoneNonCompliance::where('stage', 'response_window')->get();
        foreach ($ncs as $nc) {
            $deadline = $nc->created_at->copy()->addHours(72);

            if(in_array($nc->owner_response_type, ['completion_proof', 'rmep'])){
                $ncService->triggerIPM($nc);
            }
            elseif (Carbon::now()->greaterThanOrEqualTo($deadline)) {
                // 72 hours passed → trigger IPM voting
                $ncService->triggerIPM($nc);
            }
            //$ncService->notify('', $nc);
        }

        // Stage -3 |NC with ongoing votes
        $ncs_ipm = MilestoneNonCompliance::where('stage', 'ipm')->get();
        foreach ($ncs_ipm as $nc) {
            if(is_null($nc->ipm_started_at )){
                continue;
            }
            $ipm_started_at = $nc->ipm_started_at;
            $deadline = $ipm_started_at->copy()->addDays(7);

            if (Carbon::now()->greaterThanOrEqualTo($deadline)) {
                // 7 days passed → evaluate IPM votes as it is
                $ncService->getAndSetIPMResult($nc);
            }
        }

        // Stage -4 | NC in PID stage
        $ncs_pid = MilestoneNonCompliance::where('stage', 'pid')->get();
        foreach ($ncs_pid as $nc) {
            // Freeze & lock
            $milestone = $nc->milestone;
            $milestone->status = 'locked';
            $milestone->save();
            //$this->notify();
            #to resolve::
            //Resolution team & Project manager handles it
            //Owner provides documents
        }

        // Stage -5 | Auto Sanctions
        $cases = MilestoneNonCompliance::where('stage', 'pid')
            ->whereNull('resolved_at')
            ->where('sanctioned', false)->get();

        foreach ($cases as $nc) {
            if(is_null($nc->pid_started_at )){
                continue;
            }

            // PID timeout (example: 14 days)
            if (now()->diffInDays($nc->pid_started_at) >= 14) {
                $ncService->applySanctions($nc);
            }
        }


    }


}
