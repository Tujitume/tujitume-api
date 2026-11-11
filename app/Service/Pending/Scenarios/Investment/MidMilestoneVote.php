<?php

namespace App\Service\Pending\Scenarios\Investment;

use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\DB;

/** An investor in a milestone is asked to vote on the mid-milestone progress report (MidMilestoneController::vote_store). */
class MidMilestoneVote extends Scenario
{
    public function key(): string
    {
        return 'investor.mid_vote';
    }

    public function userTypes(): ?array
    {
        return [2];
    }

    public function collect(): array
    {
        $q = DB::table('mid_milestones as mm')->join('milestones as m', 'm.id', '=', 'mm.milestone_id')->join('listings as l', 'l.id', '=', 'm.listing_id')
            ->where('mm.status', 'pending_investor_review')->where(fn ($w) => $w->whereNull('m.fund_released')->orWhere('m.fund_released', 0))
            ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('accepted_bids as ab')->whereColumn('ab.ms_id', 'm.id')->where('ab.investor_id', $this->user->id))
            ->whereNotExists(fn ($e) => $e->select(DB::raw(1))->from('mid_milestone_votes as v')->whereColumn('v.mid_milestone_id', 'mm.id')->where('v.investor_id', $this->user->id))
            ->select('mm.id', 'm.title', 'l.name');

        return [$this->group('investments', $q, fn ($r) => $this->item('investor.mid_vote', 'Mid-milestone progress waiting for your vote', $r->name.' · '.$r->title, 'dashboard.investor.dealroom', $r->id))];
    }
}
