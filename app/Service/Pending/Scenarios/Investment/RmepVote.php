<?php

namespace App\Service\Pending\Scenarios\Investment;

use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\DB;

/** An investor listed as eligible is asked to vote on a milestone's RMEP (PreReleaseRmepController::vote_store). */
class RmepVote extends Scenario
{
    public function key(): string
    {
        return 'investor.rmep_vote';
    }

    public function userTypes(): ?array
    {
        return [2];
    }

    public function collect(): array
    {
        // eligible_voters is a JSON list of investor ids, so the match is made here rather than in SQL
        $rows = DB::table('milestone_rmeps as r')->join('milestones as m', 'm.id', '=', 'r.milestone_id')->where('r.status', 'active')
            ->whereNotExists(fn ($e) => $e->select(DB::raw(1))->from('rmep_votes as v')->whereColumn('v.rmep_id', 'r.id')->where('v.investor_id', $this->user->id))
            ->select('r.id', 'r.milestone_name', 'r.voting_deadline', 'r.eligible_voters', 'm.title')->get()
            ->filter(fn ($r) => in_array((int) $this->user->id, array_map('intval', (array) json_decode((string) $r->eligible_voters, true)), true))->values();

        return [['group' => 'investments', 'count' => $rows->count(), 'items' => $rows->take(self::MAX_ITEMS)->map(fn ($r) => $this->item('investor.rmep_vote', 'Execution plan waiting for your vote', $r->milestone_name ?: $r->title, 'dashboard.investor.dealroom', $r->id, $this->date($r->voting_deadline)))->all()]];
    }
}
