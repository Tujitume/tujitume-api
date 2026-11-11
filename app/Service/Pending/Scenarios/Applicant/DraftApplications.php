<?php

namespace App\Service\Pending\Scenarios\Applicant;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class DraftApplications extends Scenario
{
    public function key(): string
    {
        return 'applicant.drafts';
    }

    public function userTypes(): ?array
    {
        return [1];
    }

    public function collect(): array
    {
        $q = $this->applications()->where('a.status', 'draft')->select('a.id', 'p.program_title', 'p.application_deadline');

        return [$this->group('programs', $q, fn ($r) => $this->item('applicant.draft', 'Finish your application', $r->program_title, 'dashboard.entrepreneur.grantsApplication', $r->id, $this->date($r->application_deadline)))];
    }
}
