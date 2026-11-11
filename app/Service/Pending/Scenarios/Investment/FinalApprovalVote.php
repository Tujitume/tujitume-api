<?php

namespace App\Service\Pending\Scenarios\Investment;

use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\DB;

/** An investor in a milestone is asked to vote on the final execution proof while its voting window is open (MilestoneFinalApprovalController::final_approval_vote). */
class FinalApprovalVote extends Scenario
{
    public function key(): string
    {
        return 'investor.final_vote';
    }

    public function userTypes(): ?array
    {
        return [2];
    }

    public function collect(): array
    {
        $q = DB::table('milestone_execution_documents as e')->join('milestones as m', 'm.id', '=', 'e.milestone_id')->join('listings as l', 'l.id', '=', 'm.listing_id')
            ->where('e.status', 'submitted')
            ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('votes as w')->whereColumn('w.reference_id', 'e.id')->where('w.status', 'open'))
            ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('accepted_bids as ab')->whereColumn('ab.ms_id', 'm.id')->where('ab.investor_id', $this->user->id))
            ->whereNotExists(fn ($e) => $e->select(DB::raw(1))->from('final_approval_votes as v')->whereColumn('v.final_approval_id', 'e.id')->where('v.investor_id', $this->user->id))
            ->select('e.id', 'm.title', 'l.name');

        return [$this->group('investments', $q, fn ($r) => $this->item('investor.final_vote', 'Final milestone proof waiting for your vote', $r->name.' · '.$r->title, 'dashboard.investor.dealroom', $r->id))];
    }
}
