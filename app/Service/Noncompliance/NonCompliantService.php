<?php
namespace App\Service\Noncompliance;
use App\Models\Business\BusinessSanction;
use App\Models\Milestones\Milestones;
use App\Models\Milestones\NonCompliance\MilestoneNonCompliance;
use App\Service\Business\Milestone\MilestonePMCandidates;
use App\Service\Misc\GlobalVotingService;
use App\Service\Notification\EmailService;
use App\Service\Notification\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NonCompliantService
{
    protected $emailService;
    protected $notification;
    protected $votingService;
    protected $ncVotingService;
    public function __construct()
    {
        $this->emailService = new EmailService();
        $this->notification = new NotificationService();
        $this->votingService = new GlobalVotingService();
        $this->ncVotingService = new VotingService();
        $this->candidates = new MilestonePMCandidates();
    }

    #Non-Compliance: Failure to submit required milestone progress or Revised Milestone Execution Plan (RMEP) by the deadline.
    #IPM (Investor Protection Mode): Platform-triggered escalation that shifts control to investors after owner inactivity.
    #PID (Platform-Initiated Dispute): A dispute automatically opened by Tujitume, not by individual investors.


    # Trigger Non-Compliance Process
    public function trigger($milestone)
    {

    # STAGE 1 Automatic Non-Compliance Flag
        #Publicly visible on the project page
        $milestone->status = 'non_compliant'; $milestone->save();

    #STAGE 2 — Mandatory 72-Hour Response Window
        $this->startResponseWindow($milestone);

    #STAGE 3 — Investor Protection Mode (IPM)
    #STAGE 4 — Platform-Initiated Dispute (PID)
    #STAGE 5 — Automatic Sanctions

    }

    // STAGES Sub-methods
    public function startResponseWindow(Milestones $milestone)
    {
        $owner = $milestone->listing->owner;

        $nc = MilestoneNonCompliance::create([
            'milestone_id' => $milestone->id,
            'business_id' => $milestone->listing->id,
            'owner_id' => $milestone->listing->user_id,
            'stage' => 'response_window',
            'trigger_reason' => 'missed_deadline',
            'response_deadline' => now()->addHours(72),
        ]);

        // Notify
        $this->notify($owner,'response_window', $milestone);

    }

    public function triggerIPM(MilestoneNonCompliance $nc)
    {
        $milestone = $nc->milestone;
        $owner = $milestone->listing->owner;

        $openVote = $this->votingService->open('non_compliance', $nc->id, 7);
        if(!$openVote){
            throw new \Exception('Unable to open voting for Non-Compliance ID: '.$nc->id);
        }

        $nc->update([
            'stage' => 'ipm',
            'ipm_started_at' => now()
        ]);

        $this->notify($owner,'ipm_triggered', $milestone);
    }

    public function getAndSetIPMResult(MilestoneNonCompliance $nc)
    {
        $winner = $this->ncVotingService->voteResult($nc);
        if(!$winner){
            //throw new \Exception('Unable to determine winning vote');
            return;
        }

        $nc->investor_decision = $winner;
        $owner =$nc->milestone->listing->owner;

        #Stage 4 - PID if 'freeze' or 'dispute'
        if (in_array($winner, ['freeze', 'dispute'])) {
            $nc->stage = 'pid'; $nc->pid_started_at = now();

            $this->notify($owner, 'pid', $nc->milestone);
        }
        elseif ($winner == 'continue') {
            $nc->stage = 'resolved'; $nc->resolved_at = now();
            $nc->investor_decision = 'continue';

            //Extend milestone deadline by 7 days
            $milestone = $nc->milestone;
            $deadline = Carbon::parse($milestone->deadline_date);
            $milestone->deadline_date = $deadline->addDays(7);
            $milestone->save();

            $this->notify($owner, 'continue', $nc->milestone);
        }

        $nc->save();
    }

    public function applySanctions(MilestoneNonCompliance $nc)
    {
        $businessId = $nc->business_id;

        // Count previous serious sanctions (Tier 2+)
        $strikeCount = BusinessSanction::where('business_id', $businessId)
            ->whereIn('tier', ['tier_2', 'tier_3'])->count();

        DB::transaction(function () use ($nc, $businessId, $strikeCount) {

            // Deactivate old active sanctions
            BusinessSanction::where('business_id', $businessId)
                ->where('active', true)->update(['active' => false]);

            if ($strikeCount >= 1) {

                // Tier 3 — Permanent Ban
                BusinessSanction::create([
                    'business_id' => $businessId,
                    'non_compliance_id' => $nc->id,
                    'tier' => 'tier_3',
                    'reason' => 'Repeated milestone non-compliance',
                    'ends_at' => null, // permanent
                    'active' => true,
                ]);

            } else {

                // Tier 1 — Penalty
                BusinessSanction::create([
                    'business_id' => $businessId,
                    'non_compliance_id' => $nc->id,
                    'tier' => 'tier_1',
                    'reason' => 'Milestone non-compliance',
                    'ends_at' => now()->addDays(30),
                    'active' => true,
                ]);
            }

            // Mark NC resolved via sanction
            $nc->update([
                'stage' => 'sanctioned',
                'sanctioned' => true,
                'resolution_result' => 'blacklisted',
                'resolved_at' => now(),
            ]);
        });

        //$this->notifyBusinessSanction($nc);
    }

    # H E L P E R S  ---------------------------------------------------- N O T I F Y  METHODS

    public function notify($recipient, $type, $milestone, $info = null)
    {
        if ($type == 'response_window') {
            // Notify SME
            $text = "The milestone '{$milestone->title}' for the business '{$milestone->listing->name}' has been marked as Non-Compliant you have 72 hours to submit proof or rmep document to proceed.";
            $this->notification->create(
                $milestone->listing->owner->id, null, $text, 'milestones', 'milestone'
            );

            $mail_to = $recipient->email;
            $subject = 'Milestone Status Non-Compliant';
            $data = [
                'milestone_name' => $milestone->title,
                'business_name' => $milestone->listing->name,
                'days_remaining' => 0,
                'amount_raised' => $milestone->pending_collected,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];

            $this->emailService->send($subject, 'milestone.non_compliance.non_compliant', $data, $mail_to);

            #Investors notify
            $text = "The milestone '{$milestone->title}' for the business '{$milestone->listing->name}' has been marked as Non-Compliant due to failure to submit required progress or Revised Milestone Execution Plan (RMEP) by the deadline. Investors have been notified and may take further action.";
            $investors = $milestone->investors;

            foreach($investors as $investor) {
                if(!$investor || ($investor->id == $milestone->listing->owner->id) ) {
                    continue; // Skip owner or invalid investor
                }
                $this->notification->create(
                    $investor->id, null, $text, 'milestones', 'milestone'
                );

                $this->emailService->send($subject, 'milestone.non_compliance.non_compliant', $data, $investor->email);
            }

        }
        elseif ($type == 'ipm_triggered') {

            $this->votingService->voteOpenNotify($recipient, 'non_compliant', $milestone);

        }
        elseif ( $type == 'continue' ) {
            // Notify SME
            $text = "The milestone '{$milestone->title}' for the business '{$milestone->listing->name}' is extended deadline after IPM voting.";
            $this->notification->create(
                $milestone->listing->owner->id, null, $text, 'milestones', 'milestone'
            );

            $mail_to = $recipient->email;
            $subject = 'Milestone Extended After Non-Compliant';
            $data = [
                'milestone_name' => $milestone->title,
                'business_name' => $milestone->listing->name,
                'days_remaining' => 7,
                'amount_raised' => $milestone->pending_collected,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];

            $this->emailService->send($subject, 'milestone.non_compliance.continue', $data, $mail_to);

            #Investors notify
            $investors = $milestone->investors;

            foreach($investors as $investor) {
                if(!$investor || ($investor->id == $milestone->listing->owner->id) ) {
                    continue; // Skip owner or invalid investor
                }
                $this->notification->create(
                    $investor->id, null, $text, 'milestones', 'milestone'
                );

                $this->emailService->send($subject, 'milestone.non_compliance.continue', $data, $investor->email);
            }

        }
        elseif ( $type == 'pid' ) {
            // Notify SME
            $text = "The milestone '{$milestone->title}' for the business '{$milestone->listing->name}' is now in platform dispute, and is locked for further review.";
            $this->notification->create(
                $milestone->listing->owner->id, null, $text, 'milestones', 'milestone'
            );

            $mail_to = $recipient->email;
            $subject = 'Milestone Froze After Non-Compliance';
            $data = [
                'milestone_name' => $milestone->title,
                'business_name' => $milestone->listing->name,
                'days_remaining' => 7,
                'amount_raised' => $milestone->pending_collected,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];

            $this->emailService->send($subject, 'milestone.non_compliance.pid', $data, $mail_to);

            #Investors notify
            $investors = $milestone->investors;

            foreach($investors as $investor) {
                if(!$investor || ($investor->id == $milestone->listing->owner->id) ) {
                    continue; // Skip owner or invalid investor
                }
                $this->notification->create(
                    $investor->id, null, $text, 'milestones', 'milestone'
                );

                $this->emailService->send($subject, 'milestone.non_compliance.pid', $data, $investor->email);
            }
        }
        elseif ( $type == 'sanctioned' ) {
            // Notify SME
            $text = "The milestone '{$milestone->title}' for the business '{$milestone->listing->name}' is sanctioned by platform, further milestones remain locked & refund will be initiated.";
            $this->notification->create(
                $milestone->listing->owner->id, null, $text, 'milestones', 'milestone'
            );

            $mail_to = $recipient->email;
            $subject = 'Milestone Sanctioned for Non-Compliance';
            $data = [
                'milestone_name' => $milestone->title,
                'business_name' => $milestone->listing->name,
                'days_remaining' => 7,
                'amount_raised' => $milestone->pending_collected,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];

            $this->emailService->send($subject, 'milestone.non_compliance.sanctioned', $data, $mail_to);

            #Investors notify
            $investors = $milestone->investors;

            foreach($investors as $investor) {
                if(!$investor || ($investor->id == $milestone->listing->owner->id) ) {
                    continue; // Skip owner or invalid investor
                }
                $this->notification->create(
                    $investor->id, null, $text, 'milestones', 'milestone'
                );

                $this->emailService->send($subject, 'milestone.non_compliance.sanctioned', $data, $investor->email);
            }
        }
    }

}
