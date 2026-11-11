<?php

namespace App\Service\Pending\Scenarios\Applicant;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class ApplicantCheckpoints extends Scenario
{
    public function key(): string
    {
        return 'applicant.checkpoints';
    }

    public function userTypes(): ?array
    {
        return [1];
    }

    public function collect(): array
    {
        $q = DB::table('m_e_checkpoints as c')->join('program_applications as a', 'a.id', '=', 'c.app_id')->where('a.user_id', $this->user->id)->whereNotNull('c.sent_at')
            ->where(fn ($w) => $w->whereNull('c.respondent')->orWhere('c.respondent', 'applicant'))->whereIn('c.status', ['pending', 'changes_requested'])
            ->select('c.id', 'c.app_id', 'c.checkpoint_name', 'c.status', 'c.due_date');

        return [$this->group('programs', $q, fn ($r) => $this->item($r->status === 'changes_requested' ? 'applicant.checkpoint_changes' : 'applicant.checkpoint', $r->status === 'changes_requested' ? 'Changes requested on your monitoring' : 'Monitoring to complete', $r->checkpoint_name, 'dashboard.entrepreneur.grantsDealroom.monitoring::'.$r->app_id, $r->id, $this->date($r->due_date)))];
    }
}
