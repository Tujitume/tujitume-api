<?php

namespace App\Service\Pending\Scenarios\Applicant;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class ApplicantMilestones extends Scenario
{
    public function key(): string
    {
        return 'applicant.milestones';
    }

    public function userTypes(): ?array
    {
        return [1];
    }

    public function collect(): array
    {
        $q = DB::table('program_milestones as m')->join('program_applications as a', 'a.id', '=', 'm.app_id')->join('programs as p', 'p.id', '=', 'a.program_id')->where('a.user_id', $this->user->id)
            ->where(fn ($w) => $w->whereIn('m.status', ['revision_requested', 'completion_rejected'])->orWhere(fn ($x) => $x->where('m.status', 'pending')->where('m.mprv_ready', 1)))
            ->select('m.id', 'm.title', 'm.status', 'a.id as app_id', 'p.program_title');

        return [$this->group('programs', $q, fn ($r) => $this->item('applicant.milestone', match ($r->status) {
            'revision_requested' => 'Milestone sent back for corrections', 'completion_rejected' => 'Proof of completion needs resubmitting', default => 'Milestone ready to submit',
        }, $r->program_title.' · '.$r->title, 'dashboard.entrepreneur.programsDealroom::'.$r->app_id.'::mprv#milestone-'.$r->id, $r->id))];
    }
}
