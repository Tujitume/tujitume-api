<?php

namespace App\Service\Pending\Scenarios\Review;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class ReviewRequests extends Scenario
{
    public function key(): string
    {
        return 'review.request';
    }

    public function userTypes(): ?array
    {
        return [3, 4, 6, 7];
    }

    public function collect(): array
    {
        $q = DB::table('round_reviewers as rr')->join('program_rounds as pr', 'pr.id', '=', 'rr.round_id')->join('programs as p', 'p.id', '=', 'pr.program_id')
            ->where('rr.user_id', $this->user->id)->where('rr.acceptance_status', 'pending')
            ->select('rr.id', 'p.program_title', 'pr.round_name', 'pr.close_date');

        return [$this->group('review', $q, fn ($r) => $this->item('review.request', 'Review request', $r->program_title.' · '.$r->round_name, $this->drawer('review'), $r->id, $this->date($r->close_date)))];
    }
}
