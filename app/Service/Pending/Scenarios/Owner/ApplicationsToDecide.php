<?php

namespace App\Service\Pending\Scenarios\Owner;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class ApplicationsToDecide extends Scenario
{
    public function key(): string
    {
        return 'owner.applications';
    }

    public function userTypes(): ?array
    {
        return [4];
    }

    public function collect(): array
    {
        // Single-round programs: a submitted application waits for the owner's decision. Multi-round programs are handled per round.
        $q = DB::table('program_applications as a')->join('programs as p', 'p.id', '=', 'a.program_id')->where('p.user_id', $this->ownerId)->where('p.program_type', 'single_round')->where('a.status', 'pending')
            ->select('a.id', 'a.startup_name', 'p.program_title');

        return [$this->group('manage', $q, fn ($r) => $this->item('owner.application', 'Application waiting for your decision', ($r->startup_name ?: 'Applicant').' · '.$r->program_title, 'dashboard.programOrg.applications', $r->id))];
    }
}
