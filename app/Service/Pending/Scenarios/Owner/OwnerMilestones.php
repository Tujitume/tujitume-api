<?php

namespace App\Service\Pending\Scenarios\Owner;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class OwnerMilestones extends Scenario
{
    public function key(): string
    {
        return 'owner.mprv';
    }

    public function userTypes(): ?array
    {
        return [4];
    }

    public function collect(): array
    {
        $q = DB::table('program_milestones as m')->join('program_applications as a', 'a.id', '=', 'm.app_id')->where('a.program_owner_id', $this->ownerId)
            ->whereIn('m.status', ['submitted', 'under_review', 'completed'])->select('m.id', 'm.title', 'm.status', 'a.id as app_id', 'a.startup_name');

        return [$this->group('manage', $q, fn ($r) => $this->item($r->status === 'completed' ? 'owner.completion' : 'owner.mprv',
            $r->status === 'completed' ? 'Proof of completion to sign off' : 'Milestone to review', ($r->startup_name ?: 'Applicant').' · '.$r->title,
            'dashboard.programOrg.programDealroomDetail::'.$r->app_id.'::mprv#milestone-'.$r->id, $r->id))];
    }
}
