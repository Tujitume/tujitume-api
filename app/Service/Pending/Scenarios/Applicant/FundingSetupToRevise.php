<?php

namespace App\Service\Pending\Scenarios\Applicant;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class FundingSetupToRevise extends Scenario
{
    public function key(): string
    {
        return 'applicant.funding';
    }

    public function userTypes(): ?array
    {
        return [1];
    }

    public function collect(): array
    {
        $q = $this->applications()->whereIn('a.funding_setup_status', ['awaiting_applicant_revision', 'revision_requested'])->select('a.id', 'p.program_title', 'a.revision_notes');

        return [$this->group('programs', $q, fn ($r) => $this->item('applicant.funding', 'Your funding plan needs your input', $r->program_title, 'dashboard.entrepreneur.programsDealroom::'.$r->id.'::funding-setup', $r->id))];
    }
}
