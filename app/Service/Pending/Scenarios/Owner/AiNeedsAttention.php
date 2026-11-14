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
        // Only each application's newest review counts (a failed one that was run again is no longer a problem), only applications that are
        // still in that round and submitted, and not the test-data generator's
        $latest = DB::table('application_ai_reviews')->where('status', '!=', 'stale')->selectRaw('application_id, MAX(id) as review_id')->groupBy('application_id');
        $query = DB::table('application_ai_reviews as r')
            ->joinSub($latest, 'lr', fn ($join) => $join->on('lr.review_id', '=', 'r.id'))
            ->join('program_applications as pa', 'pa.id', '=', 'r.application_id')
            ->join('program_rounds as pr', 'pr.id', '=', 'r.round_id')->join('programs as p', 'p.id', '=', 'pr.program_id')
            ->whereColumn('pa.current_round_id', 'r.round_id')->whereIn('pa.round_status', \App\Models\Programs\ProgramApplication::AI_REVIEWABLE_STATUSES)
            ->where('p.user_id', $this->ownerId)->whereIn('r.status', ['needs_attention', 'failed'])->whereNotIn('pr.status', ['finalized']);
        if (! \App\Service\Misc\AutomatedAccounts::$reviewWithAi) {
            $query->whereNotIn('pa.user_id', DB::table('users')->where('email', 'like', \App\Service\Misc\AutomatedAccounts::EMAIL_LIKE)->select('id'));
        }
        $rows = $query->groupBy('pr.id', 'pr.program_id', 'pr.round_name', 'p.program_title')->select('pr.id', 'pr.program_id', 'pr.round_name', 'p.program_title', DB::raw('count(*) as n'))->get();

        return [['group' => 'manage', 'count' => (int) $rows->sum('n'), 'items' => $rows->take(self::MAX_ITEMS)->map(fn ($r) => $this->item('owner.ai_attention', 'AI reviews need attention', $r->program_title.' · '.$r->round_name, 'dashboard.programOrg.applicationsMultiRound::id='.$r->program_id.'::roundId='.$r->id.'?tab=ai-review&ai-view=attention', $r->id, null, (int) $r->n))->all()]];
    }
}
