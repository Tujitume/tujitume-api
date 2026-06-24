<?php
namespace App\Service\Business\Milestone;
use App\Service\Notification\EmailService;
use App\Service\Notification\NotificationService;
use Illuminate\Support\Facades\Auth;

class MilestoneNotificationService
{
    protected $emailService;
    protected $notification;
    //protected $Client;
    public function __construct()
    {

        $this->emailService = new EmailService();
        $this->notification = new NotificationService();
        $this->candidates = new MilestonePMCandidates();
    }

    public function notify($recipient, $type, $milestone, $info = null)
    {
        //R M E P Notifications  ----------------------------------------------------------------
        if($type == 'rmep_submitted'){
            $text = 'Milestone '. $milestone->title. ' has documents from business owner ready for review.';

            // E m a i l
            $subject = 'Business Owner Submitted RMEP';
            foreach($recipient as $investor){
                $this->notification->create(
                    $investor->id, null, $text, 'milestones', 'milestone'
                );

                $mail_to = $investor->email;
                $data = [
                    'investorName' => $investor->fname,
                    'milestoneName' => $milestone->title,
                    'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
                ];
                $this->emailService->send($subject, 'milestone.rmep.bo_submitted_rmep', $data, $mail_to);
//                Investors vote:
//                Stay invested → BO tops up or adjusts scope
//                Withdraw → milestone fails & refunds issued

            }

        }
        if($type == 'rmep_approved'){
            $text = 'Milestone RMEP '. $milestone->title. ' has been approved by investors.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l  to  SME
            $subject = 'Milestone RMEP Approved';
            $mail_to = $recipient->email;
            $data = [
                'boName' => $recipient->fname,
                'milestoneName' => $milestone->title,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.rmep.approved', $data, $mail_to);

            // Notice  to  Investors
            $investors = $milestone->investors;
            foreach($investors as $investor) {
                $this->notification->create(
                    $investor->id, null, $text, 'milestones', 'milestone'
                );
            }
        }
        if($type == 'rmep_rejected'){
            $text = 'Milestone RMEP '. $milestone->title. ' has been rejected by investors.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l  to  SME
            $subject = 'Milestone RMEP Rejected';
            $mail_to = $recipient->email;
            $data = [
                'boName' => $recipient->fname,
                'milestoneName' => $milestone->title,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.rmep.rejected', $data, $mail_to);

            // E m a i l  to  Investors
            $investors = $milestone->investors;
            foreach($investors as $investor){
                $this->notification->create(
                    $investor->id, null, $text, 'milestones', 'milestone'
                );

                $mail_to = $investor->email;
                $data = [
                    'investorName' => $investor->fname,
                    'milestoneName' => $milestone->title,
                    'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
                ];
                $this->emailService->send($subject, 'milestone.rmep.rejected_to_all', $data, $mail_to);
            }
            //Reject condition if any
        }
        //R M E P   ENDS


        // P R E   R E L E A S E ----------------------------------------------------------------
        if($type == 'pre_release_requested'){
            $investor = Auth::user();
            $text = 'Milestone '. $milestone->title. ' was requested pre release verification by investors.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l
            $subject = 'Milestone Pre Release Requested';
            $mail_to = $recipient->email;
            $data = [
                'boName' => $recipient->fname,
                'investorName' => $investor->fname. ' '.$investor->lname ,
                'documents' => $info, // e.g., ['Invoice','Certificate']
                'dashboardUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.pre_release.pre_release_requested', $data, $mail_to);
        }
        if($type == 'docs_ready_for_review'){

            $text = 'Milestone '. $milestone->title. ' has documents from business owner ready for review.';
            $subject = 'Pre Release Documents Submitted ';
            $investors = $milestone->investors;

            foreach($investors as $investor){
                $this->notification->create(
                    $investor->id, null, $text, 'milestones', 'milestone'
                );

                $mail_to = $investor->email;
                $data = [
                    'investorName' => $investor->fname,
                    'milestoneName' => $milestone->title,
                    'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
                ];
                $this->emailService->send($subject, 'milestone.pre_release.bo_submitted_documents', $data, $mail_to);
            }

        }
        if($type == 'pr_approved'){
            $text = 'Milestone '. $milestone->title. ' has been approved by investors, 75% ($'.$milestone->amount * .75.') funds released to your account.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l
            $subject = 'Milestone Approved, Funds Released';
            $mail_to = $recipient->email;
            $data = [
                'boName' => $recipient->fname,
                'milestoneName' => $milestone->title,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.pre_release.approved', $data, $mail_to);

            // Mid-Milestone Request
            //$this->emailService->send('Mid Milestone Required', 'milestone.mid_milestone.system_request', $data, $mail_to);

            // Notice  to  Investors
            $investors = $milestone->investors;
            foreach($investors as $investor) {
                $text1 = 'Milestone '. $milestone->title. ' has been approved by investors, 75% ($'.$milestone->amount * .75.') funds released.';
                $this->notification->create(
                    $investor->id, null, $text1, 'milestones', 'milestone'
                );
            }

        }
        if($type == 'pr_rejected'){
            $text = 'Milestone '. $milestone->title. ' has been rejected by investors.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l
            $subject = 'Milestone Pre Release Rejected';
            $mail_to = $recipient->email;
            $data = [
                'boName' => $recipient->fname,
                'milestoneName' => $milestone->title,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.pre_release.rejected', $data, $mail_to);

            // Notice  to  Investors
            $investors = $milestone->investors;
            foreach($investors as $investor) {
                $text1 = 'Milestone '. $milestone->title. ' has been rejected by investors.';
                $this->notification->create(
                    $investor->id, null, $text1, 'milestones', 'milestone'
                );
            }

            //If investor rejects AND admin confirms issue is valid:
            //Milestone moves into: Pre-Execution Dispute Review
            //ADMIN options:
            //Force BO to revise documents
            //Request PM pre-visit (cost to investor or shared)
            //Deny milestone activation
            //Refund investor bid for this milestone only
            //Replace BO milestone plan

        }
        if($type == 'pr_rejected_by_one'){
            $investor = Auth::user();
            $text = 'Milestone '. $milestone->title. ' has been rejected by '.$investor->fname.' '.$investor->lname. ',Please go to dealroom and resubmit you documents.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l
            $subject = 'Milestone Pre Release Rejected';
            $mail_to = $recipient->email;
            $data = [
                'boName' => $recipient->fname,
                'investorName' => $investor->fname.' '.$investor->lname,
                'milestoneName' => $milestone->title,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.pre_release.rejected_by_one', $data, $mail_to);


        }
        if($type == 'pr_pm_audit'){
            $text = 'Milestone '. $milestone->title. ' has went for Project manager audit.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l
            $subject = 'Milestone Is In Project manager audit';
            $mail_to = $recipient->email;
            $data = [
                'investorName' => $recipient->fname,
                'milestoneName' => $milestone->title,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.pre_release.needs_pm_audit', $data, $mail_to);
        }
        if($type == 'pr_admin_escalation'){ //Single pr request
            $text = 'Milestone '. $milestone->title. ' has went for admin review.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l
            $subject = 'Milestone Is In Admin Review';
            $mail_to = 'stevemonitoring.gathirus@gmail.com'; //$recipient->email; Admin
            $data = [
                'investorName' => $recipient->fname,
                'milestoneName' => $milestone->title,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.pre_release.admin_escalation', $data, $mail_to);
        }

        // P R E   R E L E A S E  ENDS


        //M I D - M I L E S T O N E  Notifications ----------------------------------------------------------------

        if($type == 'mid_milestone_submitted'){

            $text = 'Milestone '. $milestone->title. ' has documents from business owner ready for review.';
            $subject = 'Mid Milestone Documents Submitted ';
            $investors = $milestone->investors;

            foreach($investors as $investor){
                $this->notification->create(
                    $investor->id, null, $text, 'milestones', 'milestone'
                );

                $mail_to = $investor->email;
                $data = [
                    'investorName' => $investor->fname,
                    'milestoneName' => $milestone->title,
                    'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
                ];
                $this->emailService->send($subject, 'milestone.mid_milestone.bo_submitted_documents', $data, $mail_to);
            }
        }
        if($type == 'approved'){
            $text = 'Mid Milestone '. $milestone->title. ' has been approved by investors.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l
            $subject = 'Mid Milestone Approved, Funds Released';
            $mail_to = $recipient->email;
            $data = [
                'boName' => $recipient->fname,
                'milestoneName' => $milestone->title,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.mid_milestone.approved', $data, $mail_to);

            // Notify Investors
            $investors = $milestone->investors;
            foreach($investors as $investor) {
                $this->notification->create(
                    $investor->id, null, $text, 'milestones', 'milestone'
                );
            }
        }
        if($type == 'rejected'){
            //$milestone = $midMilestone->milestone;
            $midMilestone = $milestone;

            $text = 'Milestone '. $midMilestone->milestone->title. ' has been rejected by investors.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );
            //BO receives the selected platform reason:list
            //BO must re-upload improved evidence as a new mid-milestone

            // E m a i l
            $reasons = $midMilestone->votes()->pluck('reason');
            $sortedReasons = $reasons->countBy()->sortDesc()->keys()->take(5);
            $reasons_list = $sortedReasons->map(fn($r) => '<li>'.e($r).'</li>')->implode('');

            $subject = 'Milestone Rejected';
            $mail_to = $recipient->email;

            $data = [
                'boName' => $recipient->fname,
                'milestoneName' => $midMilestone->milestone->title,
                'reasons_list' => $reasons_list,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.mid_milestone.rejected', $data, $mail_to);

            // Notify Investors
            $investors = $midMilestone->milestone->investors;
            foreach($investors as $investor) {
                $this->notification->create(
                    $investor->id, null, $text, 'milestones', 'milestone'
                );
            }
        }
        if($type == 'pm_audit'){
            $text = 'Milestone '. $milestone->title. ' has gone for Project Manager review.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l  to  SME
            $subject = 'Milestone Is In Project Manger Audit';
            $mail_to = $recipient->email;
            $data = [
                'boName' => $recipient->fname,
                'milestoneName' => $milestone->title,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.mid_milestone.pm_audit_request', $data, $mail_to);

            // Notify Investors
            $investors = $milestone->investors;
            foreach($investors as $investor) {
                $this->notification->create(
                    $investor->id, null, $text, 'milestones', 'milestone'
                );
            }
        }
        // M I D - M I L E S T O N E  ENDS


        // Final  Approval Notifications  ----------------------------------------------------------------
        if($type == 'final_approval_submitted') {
            $text = 'Milestone ' . $milestone->title . ' has final approval documents from business owner ready for review.';
            $subject = 'Final Approval Documents Submitted ';
            $investors = $recipient; //$milestone->investors;
            foreach ($investors as $investor) {
                $this->notification->create(
                    $investor->id, null, $text, 'milestones', 'milestone'
                );

                $mail_to = $investor->email;
                $data = [
                    'investorName' => $investor->fname,
                    'milestoneName' => $milestone->title,
                    'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
                ];
                $this->emailService->send($subject, 'milestone.final_approval.bo_submitted_documents', $data, $mail_to);
            }
        }
        if($type == 'final_approved'){
            $text = 'Milestone '. $milestone->title. ' has been finally approved by investors, milestone is fully done, next milestone started.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l
            $subject = 'Milestone Finally Approved, Funds Released';
            $mail_to = $recipient->email;
            $data = [
                'boName' => $recipient->fname,
                'milestoneName' => $milestone->title,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.final_approval.approved', $data, $mail_to);

            // Notice  to  Investors
            $investors = $milestone->investors;
            foreach($investors as $investor) {
                $text1 = 'Milestone '. $milestone->title. ' has been finally approved by investors, remaining 25% ($'.$milestone->amount * .25.') funds released.';
                $this->notification->create(
                    $investor->id, null, $text1, 'milestones', 'milestone'
                );
            }

        }
        if($type == 'final_rejected'){
            $text = 'Milestone '. $milestone->title. ' final approval has been rejected by investors.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l
            $subject = 'Milestone Final Approval Rejected';
            $mail_to = $recipient->email;
            $data = [
                'boName' => $recipient->fname,
                'milestoneName' => $milestone->title,
                'reasons_list' => [], //$reasons_list,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.final_approval.rejected', $data, $mail_to);

            // Notice  to  Investors
            $investors = $milestone->investors;
            foreach($investors as $investor) {
                $text1 = 'Milestone '. $milestone->title. ' final approval  has been rejected by investors.';
                $this->notification->create(
                    $investor->id, null, $text1, 'milestones', 'milestone'
                );
            }

        }
        if($type == 'final_pm_audit'){
            $text = 'Milestone '. $milestone->title. ' has went for Project manager final audit.';
            $this->notification->create(
                $recipient->id, null, $text, 'milestones', 'milestone'
            );

            // E m a i l
            $subject = 'Milestone Is In Project manager final audit';
            $mail_to = $recipient->email;
            $data = [
                'boName' => $recipient->fname,
                'milestoneName' => $milestone->title,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];
            $this->emailService->send($subject, 'milestone.final_approval.pm_audit_request', $data, $mail_to);

            // Notice  to  Investors
            $investors = $milestone->investors;
            foreach($investors as $investor) {
                $text1 = 'Milestone '. $milestone->title. ' final approval  has gone for project manager audit, please vote for project manager from dealroom.';
                $this->notification->create(
                    $investor->id, null, $text1, 'milestones', 'milestone'
                );
            }
        }
        // Final Approval ENDS

    }
}
