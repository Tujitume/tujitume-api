<?php

namespace App\Service\Pending\Scenarios\Review;

use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\DB;

/** A project manager: program milestone audits a program owner sent them (to accept or decline, or to report). */
class ProgramPmAudits extends Scenario
{
    private const STAGE_ROUTE = [
        'mprv' => 'dashboard.serviceProvider.pmAudits.preRelease',
        'mid_milestone' => 'dashboard.serviceProvider.pmAudits.midMilestone',
        'final_approval' => 'dashboard.serviceProvider.pmAudits.finalApproval',
    ];

    public function key(): string
    {
        return 'audit.program';
    }

    public function userTypes(): ?array
    {
        return [3];
    }

    public function collect(): array
    {
        // the same rule as ProjectManagerAudits (needs_you): still open, and not yet decided by the owner
        $q = DB::table('milestone_verifications as v')
            ->where('v.auditor_id', $this->user->id)->where('v.decision', 'audit_requested')
            ->where(fn ($w) => $w->whereNull('v.audit_status')->orWhereIn('v.audit_status', ['pending', 'accepted']))
            ->whereIn('v.verification_type', array_keys(self::STAGE_ROUTE))
            ->orderByDesc('v.id')
            ->select('v.id', 'v.verification_type', 'v.audit_status');

        return [$this->group('review', $q, fn ($r) => $this->item(
            'audit.program',
            ($r->audit_status ?: 'pending') === 'pending' ? 'Program audit to accept or decline' : 'Program audit report to send',
            null,
            self::STAGE_ROUTE[$r->verification_type].'?source=program',
            $r->id,
        ))];
    }
}
