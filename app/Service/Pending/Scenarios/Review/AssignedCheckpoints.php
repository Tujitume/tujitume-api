<?php

namespace App\Service\Pending\Scenarios\Review;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class AssignedCheckpoints extends Scenario
{
    public function key(): string
    {
        return 'review.checkpoint';
    }

    public function userTypes(): ?array
    {
        return [3, 4, 6, 7];
    }

    public function collect(): array
    {
        $q = DB::table('m_e_checkpoints')->where('respondent', 'organization')->where('assigned_to', $this->user->id)->whereNotNull('sent_at')->whereIn('status', ['pending', 'changes_requested'])
            ->select('id', 'checkpoint_name', 'status', 'due_date');

        return [$this->group('review', $q, fn ($r) => $this->item($r->status === 'changes_requested' ? 'review.checkpoint_changes' : 'review.checkpoint', $r->status === 'changes_requested' ? 'Changes requested on assigned work' : 'Assigned work to complete', $r->checkpoint_name, $this->drawer('checkpoint'), $r->id, $this->date($r->due_date)))];
    }
}
