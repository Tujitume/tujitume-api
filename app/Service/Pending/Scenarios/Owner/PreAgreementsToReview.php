<?php

namespace App\Service\Pending\Scenarios\Owner;

use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\DB;

/** The applicant answered a milestone verification agreement; the program owner must approve or reject it (MilestonePreAgreementController::approve/reject). */
class PreAgreementsToReview extends Scenario
{
    public function key(): string
    {
        return 'owner.agreements';
    }

    public function userTypes(): ?array
    {
        return [4];
    }

    public function collect(): array
    {
        $q = DB::table('milestone_pre_agreements as g')->join('program_milestones as m', 'm.id', '=', 'g.milestone_id')->join('program_applications as a', 'a.id', '=', 'm.app_id')
            ->where('a.program_owner_id', $this->ownerId)->where('g.status', 'submitted')->select('g.id', 'g.verification_type', 'm.title', 'a.id as app_id', 'a.startup_name');

        return [$this->group('manage', $q, fn ($r) => $this->item('owner.agreement', 'Verification agreement to review', ($r->startup_name ?: 'Applicant').' · '.$r->title.' · '.str_replace('_', ' ', $r->verification_type), 'dashboard.programOrg.programDealroomDetail::'.$r->app_id.'::funding-setup', $r->id))];
    }
}
