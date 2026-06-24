<?php

namespace App\Service\Business\Milestone;

use App\Models\Business\Listing;
use App\Models\Milestones\Milestones;
use App\Service\Notification\EmailService;
use App\Service\Notification\NotificationService;
use Carbon\Carbon;

class MilestoneLifecycleService
{

    protected $emailService;
    protected $notification;
    public function __construct()
    {
        $this->emailService = new EmailService();
        $this->notification = new NotificationService();
    }
    /**
     * Evaluate all milestones for a given listing
     * Applies sequential funding, risk detection, auto-extension, continuation flow, and status updates.
     */

    //        PENDING MONEY  → decides viability & risk
    //        REAL MONEY     → decides execution & audits
    //        NEVER MIX THEM
    public function evaluateListing(Listing $listing)
    {
        // Get milestones in sequential order
        $milestones = $listing->milestones()->orderBy('id')->get();
        $now = Carbon::now();
        $today = Carbon::today();

        //$activeMilestoneFound = false;

        foreach ($milestones as $index => $milestone) {

            //Lock unlock based on start_date
            if (
                $milestone->start_date &&
                $now->lt($milestone->start_date) &&
                $milestone->status == 'to_do'
            ) {
                $milestone->status = 'locked';
                $milestone->active = false;
                $milestone->save();
                continue;
            }

            if (
                $milestone->status == 'locked' &&
                (!$milestone->start_date || $now->gte($milestone->start_date))
            ) {
                // Unlock ONLY from locked
                $milestone->status = 'to_do';
            }


            // --- Funding Calculation --- / / PENDING vs REAL
            if ($milestone->listing->threshold_met
                && ( $milestone->funding_collected > $milestone->pending_collected )) {
                $funds_collected = $milestone->funding_collected;
            }
            else{
                $funds_collected = $milestone->pending_collected;
            }

            $milestone->progress_percentage = $milestone->amount
                ? min(100, round(($funds_collected / $milestone->amount) * 100))
                : 0;


            // --- Date Calculations ---
            $deadline = Carbon::parse($milestone->deadline_date);
            $diff     = $now->diff($deadline, false);

            $daysLeft  = $diff->invert ? -1 : $diff->days;
            $isOverdue = $diff->invert === 1;

            $milestone->due_in = !$isOverdue
                ? "{$diff->days} days {$diff->h} h {$diff->i} m"
                : 'overdue';

            if($milestone->progress_percentage >= 100 || $milestone->is_funded){
                $isOverdue = false;
            }

//            if ($daysToDeadline === 0 && !$isOverdue && $days === 0 && ($hours > 0 || $minutes > 0)) {
//                $daysToDeadline = 1;
//            }

            // TERMINAL & MANUAL STATES (IMMUNE)
            if (in_array($milestone->status, [
                'done',
                'in_progress',
                'in_mid_audit',
                'in_pr_audit',
                'in_final_audit',
                'rmep_submitted',
                'mid_milestone_submitted',
                'pr_approved',
                'pr_rejected',
                'admin_review',
                'execution_submitted',
                'continuation_triggered',
                'non_compliant'
            ])) {
                $milestone->save();
                continue;
            }

            //OVERDUE ESCALATION (HIGHEST AUTO PRIORITY)
            if ($isOverdue && $milestone->progress_percentage < 60) {

                if ($milestone->status != 'non_compliant') {
                    $milestone->status = 'non_compliant';
                    $milestone->active = false;

                    // Escalation hooks
                    //$this->notify('non_compliant', $milestone->listing->owner, $milestone);
                }

                $milestone->save();
                continue; // STOP lifecycle here
            }

             //6️⃣ AUTO LIFECYCLE (ONLY WHEN !OVERDUE)
            if (!$isOverdue && $milestone->status != 'locked') {

                // Fully funded
                if ($milestone->progress_percentage >= 100 && !$milestone->fund_released_75) {
                    $milestone->status    = 'fully_funded';
                    $milestone->is_funded = true;
                }

                // Pre-release completed → in_progress
                elseif ($milestone->progress_percentage >= 100 && $milestone->fund_released_75) {
                    $milestone->status    = 'in_progress';
                    $milestone->is_funded = true;
                }

                // At risk (2 days left & <60%)
                elseif ($milestone->progress_percentage < 60 && $daysLeft <= 2) {
                    $milestone->status = 'at_risk';
                }
                // from at_risk to to_do (if progress improved)
                elseif ($milestone->status == 'at_risk' && $milestone->progress_percentage >= 60
                    && $daysLeft > 0) {
                    $milestone->status = 'to_do';
                }

                // Auto-extension (on deadline & >80%)
                elseif (
                    $milestone->progress_percentage > 80 &&
                    $milestone->progress_percentage < 100 &&
                    $daysLeft === 0 &&
                    !$milestone->extended_before
                ) {
                    $milestone->deadline_date   = $deadline->addDays(7);
                    $milestone->extended_before = true;
                    $milestone->status          = 'to_do';

                    //$this->notify('auto_extension', $listing->owner, $milestone);
                }

                // Continuation (on deadline & 60–80%)
                elseif (
                    $milestone->progress_percentage >= 60 &&
                    $milestone->progress_percentage < 80 &&
                    $daysLeft === 0
                ) {
                    $this->triggerContinuation($milestone);
                    continue;
                }
            }

            //Exec Deadline logic can be added here
//            if($milestone->is_funded || $milestone->progress_percentage >= 100){
//                $deadline = Carbon::parse($milestone->exec_deadline_date);
//
//                $diffTime = $now->diff(Carbon::parse($deadline), false);
//                $diffTimeExec = $now->diff(Carbon::parse($deadline), false);
//
//                //$days = $diffTimeExec->days; $hours = $diffTime->h; $minutes = $diffTimeExec->i;
//                $isOverdue = $diffTimeExec->invert === 1; // 1 = past, 0 = upcoming
//
//                $milestone->due_in = !$isOverdue
//                    ? "{$diffTimeExec->days} days {$diffTimeExec->h} h {$diffTimeExec->i} m"
//                    : 'overdue';
//            }

            $milestone->save();
            //$milestone->listing_name = $listing->name;
            $milestone->daysLeft = $daysLeft;


        }

        // --- Sequential Lock for future milestones ---
        $this->enforceSequentialLock($listing);

        return $milestones;
    }

    //  H      E      L      P       E      R      S


    // -------------------------
    // Continuation Flow
    // -------------------------
    protected function triggerContinuation(Milestones $milestone)
    {
        // 1. Notify investors of RMEP
        //Business owner uploads a Revised Milestone Execution Plan (RMEP)
        //describing how they will fund the gap.
        //All milestone investors receive a Continuation Vote NotificationService.
        //Investors have 7 days to:Stay invested ❌ Request refund

        // 2. Mark milestone active for continuation
        $milestone->status = 'continuation_triggered';
        //if(majority votes +)
        //$milestone->status = 'to_do';
        $milestone->active = true;
        $milestone->save();
        $this->notify('continuation_alert_BO', $milestone->listing->owner, $milestone);
    }

    // -------------------------
    // Refunds
    // -------------------------
    protected function refundMilestone(Milestones $milestone)
    {
        foreach ($milestone->accepted_bids as $bid) {
            $bid->status = 'refunded';
            $bid->save();
            // TODO: integrate actual payment refund
        }
    }


    // -------------------------
    // Lock future milestones if previous failed
    // -------------------------
    protected function enforceSequentialLock(Listing $listing)
    {
        $milestones   = $listing->milestones()->orderBy('id')->get();
        $previousDone = true;

        foreach ($milestones as $milestone) {

            // If previous milestone is NOT done → force lock
            if (!$previousDone) {
                if ($milestone->status != 'locked') {
                    $milestone->status = 'locked';
                }
                $milestone->active = false;
                $milestone->save();
                continue;
            }

            // Previous done → this one can be unlocked
            if ($milestone->status == 'locked' &&
                (!$milestone->start_date || now()->gte($milestone->start_date)) ){
                $milestone->status = 'to_do';
            }

            // Active only if not completed
            $milestone->active = ($milestone->status != 'done');
            $milestone->save();

            // Only DONE unlocks the next
            $previousDone = ($milestone->status == 'done');
        }
    }

    // -------------------------
    // Notifications & Investor Nudges
    // -------------------------
    protected function sendMilestoneNotifications(Milestones $milestone, $previousStatus, $daysToDeadline)
    {
        // Email 1 — 7 Days Before Deadline
        if ($daysToDeadline === 7) {
            $this->notify('week_before', $milestone->listing->owner, $milestone);
        }

        // Email 2 — 2 Days Before Deadline AND <60%
        if ($daysToDeadline === 2 && $milestone->progress_percentage < 60) {
            $this->notify('at_risk_2_days', $milestone->listing->owner, $milestone);
        }

        // Email 3 — 1 Day Before Deadline
        if ($daysToDeadline === 1) {
            $this->notify('final_24_hours', $milestone->listing->owner, $milestone);
        }

        // Email 4 — Continuation Vote (60–99%)
        if ($milestone->progress_percentage >= 60 && $milestone->progress_percentage < 100
            && $previousStatus != 'to_do'
        ) {
            $this->notify('continuation_vote', $milestone->listing->owner, $milestone);
        }

        // Email 5 — Milestone Fully Funded
        if ($milestone->progress_percentage >= 100 && $previousStatus != 'in_progress') {
            $this->notify('milestone_funded', $milestone->listing->owner, $milestone);
        }
    }

    // -------------------------
    // NotificationService Stub
    // -------------------------
    protected function notify(string $type, $recipient, Milestones $milestone)
    {
        // N o t i f i c a t i o n
        if($type == 'auto_extension'){
            $text = 'Milestone '. $milestone->title. ' was automatically extended by 7 days due to high funding value.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l
            $subject = 'Milestone Auto Extension';
            $mail_to = $recipient->email;
            $data = [
                'milestone_name' => $milestone->title,
                'days_remaining' => 7,
                'amount_raised' => $milestone->pending_collected,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];

            $this->emailService->send($subject, 'milestone.auto_extension', $data, $mail_to);
        }
        elseif($type == 'continuation_alert_BO'){
            $text = 'Milestone '. $milestone->title. ' has triggered a continuation vote due to funding gap. Please upload RMEP.';
            $this->notification->create(
                $recipient->id, null, $text, 'dealroom', 'milestone'
            );

            // E m a i l   To  BO
            $subject = 'Milestone Continuation RMEP';
            $mail_to = $recipient->email;
            $data = [
                'boName' => $recipient->fname,
                'milestoneName' => $milestone->title,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.rmep.system_request', $data, $mail_to);
        }
        elseif($type == 'non_compliant'){
            $recipient = $milestone->listing->owner;

            $text = 'Milestone '. $milestone->title. ' was automatically extended by 7 days due to high funding value.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l
            $subject = 'Milestone Status Non-Compliant';
            $mail_to = $recipient->email;
            $data = [
                'milestone_name' => $milestone->title,
                'days_remaining' => 7,
                'amount_raised' => $milestone->pending_collected,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];

            //$this->emailService->send($subject, 'milestone.non_compliant', $data, $mail_to);

            //non_compliantStage 0: Normal Completion Flow
                //Owner uploads milestone proof
                //Investors vote (51%)
                //Funds released
            //Stage 1: Deadline Missed
                //Status → Non-Compliant
                //72-hour countdown begins
            //Stage 2: No Response?
                //Auto-trigger IPM
            //Stage 3: IPM Voting
                //Continue
                //Freeze → PM Audit
                //Dispute → PID
            //Stage 4: Enforcement
                //Sanctions
                //Audit-driven decisions
                //Dispute outcomes
            //Stage 5: Finalization
                //Extension approved
                //Dispute closed
                //Project frozen
                //Project terminated

        }

    }

    //Spill over logic
    //if($milestone->progress_percentage > 100) {
    //$spill_amount = $milestone->funding_collected - $milestone->amount;
    //    // Cap current milestone
    //$milestone->funding_collected = $milestone->amount;
    //$milestone->progress_percentage = 100;
    //
    //    // Find the next milestone in the ordered list
    //$next = $milestones->get($index + 1);
    //if ($next) {
    //$next->funding_collected = ($next->funding_collected ?? 0) + $spill_amount;
    //
    //$next->progress_percentage = $next->amount
    //? round(($next->funding_collected / $next->amount) * 100) : 0;
    //$next->save();
    //    //echo $next->funding_collected; exit;
    //}
    //}

}
