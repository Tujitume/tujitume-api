<?php

namespace App\Service\Pending\Scenarios\Review;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class PmAudits extends Scenario
{
    public function key(): string
    {
        return 'audit.pm';
    }

    public function userTypes(): ?array
    {
        return [3];
    }

    public function collect(): array
    {
        $q = DB::table('p_m_audits')->where('assigned_pm_id', $this->user->id)->where('status', 'pending')->select('id', 'type');

        return [$this->group('review', $q, fn ($r) => $this->item('audit.pm', 'Audit waiting for you', $r->type ? ucfirst(str_replace('_', ' ', $r->type)) : null, 'dashboard.serviceProvider.pmAudits', $r->id))];
    }
}
