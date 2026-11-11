<?php

namespace App\Service\Pending\Scenarios\Owner;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class CheckpointsToVerify extends Scenario
{
    public function key(): string
    {
        return 'owner.checkpoints';
    }

    public function userTypes(): ?array
    {
        return [4];
    }

    public function collect(): array
    {
        $q = DB::table('m_e_checkpoints as c')->join('programs as p', 'p.id', '=', 'c.program_id')->where('p.user_id', $this->ownerId)->where('c.status', 'submitted')
            ->select('c.id', 'c.app_id', 'c.checkpoint_name');

        return [$this->group('manage', $q, fn ($r) => $this->item('owner.checkpoint', 'Monitoring submission to verify', $r->checkpoint_name, 'dashboard.grantOrg.dealroom.monitoring::'.$r->app_id, $r->id))];
    }
}
