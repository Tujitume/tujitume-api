<?php

namespace App\Service\Pending\Scenarios\Owner;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class RoundsToFinalizeOrScore extends Scenario
{
    public function key(): string
    {
        return 'owner.rounds';
    }

    public function userTypes(): ?array
    {
        return [4];
    }

    public function collect(): array
    {
        $rounds = DB::table('program_rounds as pr')->join('programs as p', 'p.id', '=', 'pr.program_id')->where('p.user_id', $this->ownerId)->where('p.program_type', 'multi_round')
            ->whereIn('pr.status', ['published', 'in_review', 'closed'])->select('pr.id', 'pr.program_id', 'pr.round_name', 'pr.assignment_type', 'pr.review_method', 'p.program_title')->get();

        $toScore = [];
        $toFinalize = [];
        foreach ($rounds as $r) {
            $counts = DB::table('program_applications')->where('current_round_id', $r->id)->whereIn('round_status', ['submitted', 'scored'])
                ->selectRaw("sum(case when round_status = 'submitted' then 1 else 0 end) as unscored, sum(case when round_status = 'scored' then 1 else 0 end) as scored")->first();
            $unscored = (int) ($counts->unscored ?? 0);
            $scored = (int) ($counts->scored ?? 0);
            // The owner scores themselves in owner-only rounds
            if ($unscored > 0 && $r->assignment_type === 'owner_only' && $r->review_method !== 'ai_only') $toScore[] = [$r, $unscored];
            if ($scored > 0 && $unscored === 0) $toFinalize[] = [$r, $scored];
        }
        $link = fn ($r, $q = '') => 'dashboard.programOrg.applicationsMultiRound::id='.$r->program_id.'::roundId='.$r->id.$q;

        return [
            ['group' => 'manage', 'count' => array_sum(array_column($toScore, 1)), 'items' => array_map(fn ($x) => $this->item('owner.round_to_score', 'Applications to score', $x[0]->program_title.' · '.$x[0]->round_name, $link($x[0]), 'score'.$x[0]->id, null, $x[1]), array_slice($toScore, 0, self::MAX_ITEMS))],
            ['group' => 'manage', 'count' => count($toFinalize), 'items' => array_map(fn ($x) => $this->item('owner.round_to_finalize', 'Round fully scored: ready to finalize', $x[0]->program_title.' · '.$x[0]->round_name, $link($x[0], '?finalize=1'), 'fin'.$x[0]->id), array_slice($toFinalize, 0, self::MAX_ITEMS))],
        ];
    }
}
