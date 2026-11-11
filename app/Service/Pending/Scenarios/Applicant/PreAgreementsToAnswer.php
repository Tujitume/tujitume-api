<?php

namespace App\Service\Pending\Scenarios\Applicant;

use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\DB;

/** The program owner opened or rejected a verification agreement; the applicant must answer it so funding setup can finish. */
class PreAgreementsToAnswer extends Scenario
{
    public function key(): string
    {
        return 'applicant.agreements';
    }

    public function userTypes(): ?array
    {
        return [1];
    }

    public function collect(): array
    {
        $q = DB::table('milestone_pre_agreements as g')->join('program_milestones as m', 'm.id', '=', 'g.milestone_id')->join('program_applications as a', 'a.id', '=', 'm.app_id')
            ->where('a.user_id', $this->user->id)->whereIn('g.status', ['pending', 'rejected'])->where('a.funding_setup_status', '!=', 'completed')
            ->select('g.id', 'g.status', 'g.verification_type', 'm.title', 'a.id as app_id');

        return [$this->group('programs', $q, fn ($r) => $this->item('applicant.agreement', $r->status === 'rejected' ? 'Verification agreement sent back: respond' : 'Verification agreement waiting for your answer', $r->title.' · '.str_replace('_', ' ', $r->verification_type), 'dashboard.entrepreneur.programsDealroom::'.$r->app_id.'::funding-setup', $r->id))];
    }
}
