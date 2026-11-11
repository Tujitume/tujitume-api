<?php

namespace App\Service\Pending\Scenarios\Investment;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class PreReleaseVotes extends Scenario
{
    public function key(): string
    {
        return 'investor.votes';
    }

    public function userTypes(): ?array
    {
        return [2];
    }

    public function collect(): array
    {
        $q = DB::table('milestone_pre_release_requests')->where('investor_id', $this->user->id)->where('status', 'pending')->whereNull('vote')->select('id');

        return [$this->group('investments', $q, fn ($r) => $this->item('investor.vote', 'Milestone release waiting for your vote', null, 'dashboard.investor.dealroom', $r->id))];
    }
}
