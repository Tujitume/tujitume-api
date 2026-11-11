<?php

namespace App\Service\Pending\Scenarios\Owner;

use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\DB;

/** A disbursement to a supplier or the beneficiary failed (ProgramDisbursementController::markFailed); the owner must retry it. */
class FailedDisbursements extends Scenario
{
    public function key(): string
    {
        return 'owner.disbursements';
    }

    public function userTypes(): ?array
    {
        return [4];
    }

    public function collect(): array
    {
        $q = DB::table('disbursements as d')->join('program_milestones as m', 'm.id', '=', 'd.milestone_id')->join('program_applications as a', 'a.id', '=', 'm.app_id')
            ->where('a.program_owner_id', $this->ownerId)->where('d.status', 'failed')->select('d.id', 'd.amount', 'd.currency', 'd.failure_reason', 'm.id as milestone_id', 'm.title', 'a.id as app_id', 'a.startup_name');

        return [$this->group('manage', $q, fn ($r) => $this->item('owner.disbursement_failed', 'A payment failed: retry or fix it', ($r->startup_name ?: 'Applicant').' · '.$r->title.($r->failure_reason ? ' · '.$r->failure_reason : ''), 'dashboard.programOrg.programDealroomDetail::'.$r->app_id.'::mprv#milestone-'.$r->milestone_id, $r->id))];
    }
}
