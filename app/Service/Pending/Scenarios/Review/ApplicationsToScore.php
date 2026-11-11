<?php

namespace App\Service\Pending\Scenarios\Review;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class ApplicationsToScore extends Scenario
{
    public function key(): string
    {
        return 'review.to_score';
    }

    public function userTypes(): ?array
    {
        return [3, 4, 6, 7];
    }

    public function collect(): array
    {
        $q = DB::table('reviewer_application_assignments as a')->join('program_rounds as pr', 'pr.id', '=', 'a.round_id')->join('programs as p', 'p.id', '=', 'pr.program_id')
            ->where('a.reviewer_id', $this->user->id)->whereIn('a.status', ['assigned', 'in_review'])->whereNotIn('pr.status', ['draft', 'finalized'])
            ->groupBy('pr.id', 'p.id', 'p.program_title', 'pr.round_name', 'pr.review_period_end', 'pr.close_date')
            ->select('pr.id as round_id', 'p.id as program_id', 'p.program_title', 'pr.round_name', 'pr.review_period_end', 'pr.close_date', DB::raw('count(*) as n'));

        $link = fn ($r) => match ($this->type) {
            3 => 'dashboard.serviceProvider.reviewWorkspace::'.$r->round_id,
            4 => 'dashboard.grantOrg.applicationsAssigned',
            default => 'dashboard.reviewerGrantOrg.applicationsMultiRound::id='.$r->program_id.'::roundId='.$r->round_id,
        };
        $rows = $q->get();
        $items = $rows->take(self::MAX_ITEMS)->map(fn ($r) => $this->item('review.to_score', 'Applications to score', $r->program_title.' · '.$r->round_name, $link($r), $r->round_id, $this->date($r->review_period_end ?? $r->close_date), (int) $r->n))->all();

        return [['group' => 'review', 'count' => (int) $rows->sum('n'), 'items' => $items]];
    }
}
