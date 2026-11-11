<?php

namespace App\Service\Pending\Scenarios\Business;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class BidsToApprove extends Scenario
{
    public function key(): string
    {
        return 'business.bids';
    }

    public function userTypes(): ?array
    {
        return [1];
    }

    public function collect(): array
    {
        $q = DB::table('business_bids as b')->join('listings as l', 'l.id', '=', 'b.business_id')->where('b.owner_id', $this->user->id)
            ->whereNotExists(fn ($e) => $e->select(DB::raw(1))->from('accepted_bids as ab')->whereColumn('ab.bid_id', 'b.id'))->select('b.id', 'l.name', 'b.amount');

        return [$this->group('investments', $q, fn ($r) => $this->item('business.bid', 'New investment bid to answer', $r->name, 'dashboard.entrepreneur.investmentBids', $r->id))];
    }
}
