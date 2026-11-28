<?php

namespace App\Service\Pending\Scenarios\Owner;

use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\DB;

/** DECISION-8: checks sent back twice wait for the owner's team to pick a project manager to audit them. */
class AuditsToAssign extends Scenario
{
    public function key(): string
    {
        return 'owner.audit_needed';
    }

    public function userTypes(): ?array
    {
        return [4];
    }

    public function collect(): array
    {
        $rows = DB::table('milestone_verifications as v')
            ->join('program_milestones as m', 'm.id', '=', 'v.milestone_id')
            ->join('program_applications as a', 'a.id', '=', 'm.app_id')
            ->whereIn('a.program_id', $this->ownerPrograms()->select('id'))
            ->where('v.decision', 'pending')->where('v.audit_status', 'needed')
            ->orderByDesc('v.id')
            ->get(['v.id', 'v.verification_type', 'm.sequence_order', 'a.id as app_id', 'a.startup_name', 'v.created_at']);

        $step = ['mprv' => 'mprv', 'mid_milestone' => 'mid-milestone', 'final_approval' => 'final-approval'];
        $check = ['mprv' => 'MPRV', 'mid_milestone' => 'Mid-milestone check', 'final_approval' => 'Final approval'];

        return [['group' => 'manage', 'count' => $rows->count(), 'items' => $rows->take(self::MAX_ITEMS)->map(fn ($r) => $this->item(
            'owner.audit_needed', 'Pick a project manager to audit',
            ($r->startup_name ?: 'Application') . ' · ' . ($check[$r->verification_type] ?? 'Check') . ' · Milestone ' . $r->sequence_order,
            'dashboard.programOrg.programDealroomDetail::' . $r->app_id . '::' . ($step[$r->verification_type] ?? 'mprv') . '#milestone-' . $r->sequence_order,
            $r->id, $this->date($r->created_at),
        ))->all()]];
    }
}
