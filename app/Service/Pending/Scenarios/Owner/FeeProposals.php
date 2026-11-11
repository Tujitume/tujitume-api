<?php

namespace App\Service\Pending\Scenarios\Owner;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class FeeProposals extends Scenario
{
    public function key(): string
    {
        return 'owner.fee_proposals';
    }

    public function userTypes(): ?array
    {
        return [4];
    }

    public function collect(): array
    {
        $q = DB::table('reviewer_orders as o')->join('programs as p', 'p.id', '=', 'o.program_id')->where('p.user_id', $this->ownerId)->where('o.fee_status', 'pending_owner')
            ->select('o.id', 'p.program_title', 'o.proposed_fee');

        return [$this->group('manage', $q, fn ($r) => $this->item('owner.fee_proposal', 'Fee proposal to answer', $r->program_title, 'dashboard.grantOrg.orders#order-'.$r->id, $r->id))];
    }
}
