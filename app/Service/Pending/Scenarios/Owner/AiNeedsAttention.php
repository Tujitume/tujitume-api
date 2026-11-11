<?php

namespace App\Service\Pending\Scenarios\Owner;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class AiNeedsAttention extends Scenario
{
    public function key(): string
    {
        return 'owner.ai';
    }

    public function userTypes(): ?array
    {
        return [4];
    }

    public function collect(): array
    {
        $rows = DB::table('application_ai_reviews as r')->join('program_rounds as pr', 'pr.id', '=', 'r.round_id')->join('programs as p', 'p.id', '=', 'pr.program_id')
            ->where('p.user_id', $this->ownerId)->whereIn('r.status', ['needs_attention', 'failed'])->whereNotIn('pr.status', ['finalized'])
            ->groupBy('pr.id', 'pr.program_id', 'pr.round_name', 'p.program_title')->select('pr.id', 'pr.program_id', 'pr.round_name', 'p.program_title', DB::raw('count(*) as n'))->get();

        return [['group' => 'manage', 'count' => (int) $rows->sum('n'), 'items' => $rows->take(self::MAX_ITEMS)->map(fn ($r) => $this->item('owner.ai_attention', 'AI reviews need attention', $r->program_title.' · '.$r->round_name, 'dashboard.programOrg.applicationsMultiRound::id='.$r->program_id.'::roundId='.$r->id.'?tab=ai-review', $r->id, null, (int) $r->n))->all()]];
    }
}
