<?php

namespace App\Service\Pending\Scenarios\Applicant;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class NextRoundToSubmit extends Scenario
{
    public function key(): string
    {
        return 'applicant.next_round';
    }

    public function userTypes(): ?array
    {
        return [1];
    }

    public function collect(): array
    {
        $rows = $this->applications()->join('program_rounds as pr', 'pr.id', '=', 'a.current_round_id')->where('pr.round_number', '>', 1)->where('pr.status', 'published')
            ->whereNotIn('a.status', ['draft', 'rejected', 'withdrawn'])->select('a.id', 'a.current_round_id', 'p.program_title', 'pr.round_name', 'pr.close_date')->get();

        $due = $rows->filter(function ($r) {
            $app = \App\Models\Programs\ProgramApplication::find($r->id);
            $round = \App\Models\Programs\Rounds\ProgramRound::find($r->current_round_id);

            return $app && $round && (RoundParticipation::for($app, $round)['state'] ?? null) === 'not_submitted';
        })->values();

        return [['group' => 'programs', 'count' => $due->count(), 'items' => $due->take(self::MAX_ITEMS)->map(fn ($r) => $this->item('applicant.next_round', 'You advanced: submit your next round', $r->program_title.' · '.$r->round_name, 'dashboard.entrepreneur.programsApplication::'.$r->id, $r->id, $this->date($r->close_date)))->all()]];
    }
}
