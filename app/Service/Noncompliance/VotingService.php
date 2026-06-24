<?php
namespace App\Service\Noncompliance;
use App\Models\Milestones\NonCompliance\MilestoneNoncomplianceVotes;
use App\Service\Notification\EmailService;
use App\Service\Notification\NotificationService;


class VotingService
{
    protected $emailService;
    protected $notification;
    public function __construct()
    {
        $this->emailService = new EmailService();
        $this->notification = new NotificationService();
    }


    public function voteResult($nc)
    {
        $priority = ['dispute', 'freeze', 'continue'];
        $results = MilestoneNonComplianceVotes::where('non_compliance_id', $nc->id)
            ->selectRaw('vote, SUM(weight) as total_weight')
            ->groupBy('vote')->pluck('total_weight', 'vote');

        if ($results->isEmpty()) {
            //throw new \Exception('No votes cast');
            return false;
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

        return $winner;

    }


}
