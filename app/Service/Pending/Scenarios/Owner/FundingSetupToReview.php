<?php

namespace App\Service\Pending\Scenarios\Owner;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class FundingSetupToReview extends Scenario
{
    public function key(): string
    {
        return 'owner.funding_review';
    }

    public function userTypes(): ?array
    {
        return [4];
    }

    public function collect(): array
    {
        $q = DB::table('program_applications')->where('program_owner_id', $this->ownerId)->where('funding_setup_status', 'awaiting_owner_review')->select('id', 'startup_name');

        return [$this->group('manage', $q, fn ($r) => $this->item('owner.funding_review', 'Revised funding plan to review', $r->startup_name, 'dashboard.programOrg.programDealroomDetail::'.$r->id.'::funding-setup', $r->id))];
    }
}
