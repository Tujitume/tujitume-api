<?php

namespace App\Service\Pending\Scenarios\Review;

use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\DB;

/** A project manager: service booking audits waiting for them (to accept or decline, or to report once the fee is paid). */
class ServicePmAudits extends Scenario
{
    public function key(): string
    {
        return 'audit.service';
    }

    public function userTypes(): ?array
    {
        return [3];
    }

    public function collect(): array
    {
        $q = DB::table('service_booking_milestones as m')->join('services as s', 's.id', '=', 'm.service_id')
            ->where('m.auditor_id', $this->user->id)->whereIn('m.audit_status', ['pending', 'accepted'])
            ->where(fn ($w) => $w->whereNull('m.released')->orWhere('m.released', 0))
            ->orderByDesc('m.audit_requested_at')
            ->select('m.id', 'm.title', 'm.audit_status', 's.name as service_name');

        return [$this->group('review', $q, fn ($r) => $this->item(
            'audit.service',
            $r->audit_status === 'pending' ? 'Service audit to accept or decline' : 'Service audit report to send',
            trim($r->title.' · '.$r->service_name, ' ·'),
            'dashboard.serviceProvider.pmAudits.finalApproval?source=service',
            $r->id,
        ))];
    }
}
