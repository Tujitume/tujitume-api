<?php
namespace App\Service\Misc;
use App\Models\Milestones\FinalApprovalVote;
use App\Models\Milestones\MidMilestoneVote;
use App\Models\Milestones\MilestonePreReleaseRequest;
use App\Models\Milestones\NonCompliance\MilestoneNoncomplianceVotes;
use App\Models\Milestones\RmepVotes;
use App\Models\Shared\Vote;
use App\Service\Notification\EmailService;
use App\Service\Notification\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;


class GlobalVotingService
{
    protected $emailService;
    protected $notification;
    public function __construct()
    {
        $this->emailService = new EmailService();
        $this->notification = new NotificationService();
    }

    public function open($type, $refId, $durationDays)
    {
        try{
            $user = Auth::user();
            Vote::create([
                'type' => $type,
                'reference_id' => $refId,
                'starts_at' => now(),
                'ends_at' => now()->addDays($durationDays),
                'status' => 'open',
            ]);

            return true;
        }
        catch (\Exception $e) {
            DB::rollback();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return false;
        }
    }

    public function result($type, $milestoneContextModel)
    {
        #$milestoneContextModel ~ = PreRelease|MidMilestone|Final|RMEP;

        $priority = ['audit', 'approve', 'reject'];

        if($type == 'pre_release'){
            $results = MilestonePreReleaseRequest::where('milestone_id', $milestoneContextModel->milestone->id)
                ->selectRaw('vote, SUM(weight) as total_weight')
                ->groupBy('vote')->pluck('total_weight', 'vote');
        }
        elseif($type == 'mid_milestone'){
            $results = MidMilestoneVote::where('mid_milestone_id', $milestoneContextModel->id)
                ->selectRaw('vote, SUM(weight) as total_weight')
                ->groupBy('vote')->pluck('total_weight', 'vote');
        }
        elseif ( $type == 'final_approval' ) {
            $results = FinalApprovalVote::where('final_approval_id', $milestoneContextModel->id)
                ->selectRaw('vote, SUM(weight) as total_weight')
                ->groupBy('vote')->pluck('total_weight', 'vote');
        }
        elseif($type == 'rmep'){
            $results = RmepVotes::where('rmep_id', $milestoneContextModel->id)
                ->selectRaw('vote, SUM(weight) as total_weight')
                ->groupBy('vote')->pluck('total_weight', 'vote');
        }
        else {
            throw new \Exception('Invalid milestone context');
        }


        if ($results->isEmpty()) {
            throw new \Exception('No votes cast');
        }

        $winner = null;
        //Handle Ties
        $maxWeight = $results->max();
        $tied = $results->filter(fn ($w) => $w === $maxWeight)->keys();

        foreach ($priority as $option) {
            if ($tied->contains($option)) {
                $winner = $option;
                break;
            }
        }

        if(!$winner){
            throw new \Exception('Unable to determine winning vote');
        }

        //close window
        $window = Vote::where('type', $type)
            ->where('reference_id', $milestoneContextModel->id)
            ->where('status', 'open')->first();

        $window->update([
            'status' => 'closed',
            'ends_at' => now(),
        ]);

        return $winner;


    }

    public function voteOpenNotify($recipient, $type, $milestone, $info = null)
    {
        if ($type == 'non_compliant') {
            // E m a i l
            $mail_to = $recipient->email;
            $subject = 'IPM Voting For Milestone Non-Compliant';
            $data = [
                'milestone_name' => $milestone->title,
                'business_name' => $milestone->listing->name,
                'days_remaining' => 7,
                'amount_raised' => $milestone->pending_collected,
                'reviewUrl' => 'https://beta.tujitume.com/dashboard/milestones',
            ];

            $text = "The milestone '{$milestone->title}' for the business '{$milestone->listing->name}' has been gone for IPM voting to decide by investors whether to continue or not.";

            $this->notification->create(
                $milestone->listing->owner->id, null, $text, 'milestones', 'milestone'
            );

            $this->emailService->send($subject, 'milestone.non_compliance.ipm', $data, $mail_to);

            #Investors notify
            $investors = $milestone->investors;

            foreach ($investors as $investor) {
                if (!$investor || ($investor->id == $milestone->listing->owner->id)) {
                    continue; // Skip owner or invalid investor
                }
                $this->notification->create(
                    $investor->id, null, $text, 'milestones', 'milestone'
                );

                $this->emailService->send($subject, 'milestone.non_compliance.ipm', $data, $investor->email);
            }

        }
        elseif ( $type == 'rmep' ) {
            //
        }
        elseif ( $type == 'pre_release' ) {
            //
        }
        elseif ( $type == 'mid_milestone' ) {
            //
        }
        elseif ( $type == 'final_approval' ) {
            //
        }
    }

    // Evaluate votes and set decision
    protected function evaluateVotes($nc)
    {
        $priority = ['dispute', 'freeze', 'continue'];
        $results = MilestoneNonComplianceVotes::where('non_compliance_id', $nc->id)
            ->selectRaw('vote, SUM(weight) as total_weight')
            ->groupBy('vote')->pluck('total_weight', 'vote');

        if ($results->isEmpty()) {
            throw new \Exception('No votes cast');
        }

        $winner = null;
        //Handle Ties
        $maxWeight = $results->max();
        $tied = $results->filter(fn ($w) => $w === $maxWeight)->keys();

        foreach ($priority as $option) {
            if ($tied->contains($option)) {
                $winner = $option;
                break;
            }
        }

        if(!$winner){
            throw new \Exception('Unable to determine winning vote');
        }

        if ($winner == 'continue') {
            $nc->investor_decision = 'continue';
        }
        elseif ($winner == 'freeze') {
            $nc->investor_decision = 'freeze';
        }
        elseif ($winner == 'dispute') {
            $nc->investor_decision = 'dispute';
        }

        $nc->save();
        //$this->notify($nc->milestone->listing->owner, $winner, $nc);

    }



}
