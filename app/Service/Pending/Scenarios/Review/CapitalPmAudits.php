<?php

namespace App\Service\Pending\Scenarios\Review;

use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\DB;

/** A project manager: capital audits waiting for them (to accept or decline, or to report once the fee is paid). */
class CapitalPmAudits extends Scenario
{
    public function key(): string
    {
        return 'audit.capital';
    }

    public function userTypes(): ?array
    {
        return [3];
    }

    public function collect(): array
    {
        $q = DB::table('capital_milestones as m')->join('startup_pitches as p', 'p.id', '=', 'm.app_id')
            ->where('m.auditor_id', $this->user->id)->whereIn('m.audit_status', ['pending', 'accepted'])
            ->where(fn ($w) => $w->whereNull('m.fund_released')->orWhere('m.fund_released', false))
            ->orderByDesc('m.audit_requested_at')
            ->select('m.id', 'm.title', 'm.audit_status', 'p.startup_name');

        return [$this->group('review', $q, fn ($r) => $this->item(
            'audit.capital',
            $r->audit_status === 'pending' ? 'Capital audit to accept or decline' : 'Capital audit report to send',
            trim($r->title.' · '.$r->startup_name, ' ·'),
            'dashboard.serviceProvider.pmAudits.finalApproval?source=capital',
            $r->id,
        ))];
    }
}
