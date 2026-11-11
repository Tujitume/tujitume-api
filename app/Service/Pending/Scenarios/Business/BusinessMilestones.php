<?php

namespace App\Service\Pending\Scenarios\Business;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class BusinessMilestones extends Scenario
{
    public function key(): string
    {
        return 'business.milestones';
    }

    public function userTypes(): ?array
    {
        return [1];
    }

    public function collect(): array
    {
        $q = DB::table('milestones as m')->join('listings as l', 'l.id', '=', 'm.listing_id')->where('m.user_id', $this->user->id)->whereIn('m.status', ['at_risk', 'overdue'])
            ->select('m.id', 'm.title', 'm.status', 'm.deadline_date', 'l.name');

        return [$this->group('investments', $q, fn ($r) => $this->item($r->status === 'overdue' ? 'business.milestone_overdue' : 'business.milestone_at_risk', $r->status === 'overdue' ? 'Milestone overdue' : 'Milestone at risk', $r->name.' · '.$r->title, 'dashboard.entrepreneur.milestones', $r->id, $this->date($r->deadline_date)))];
    }
}
