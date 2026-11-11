<?php

namespace App\Service\Pending\Scenarios\Investment;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class BidsAwaitingPayment extends Scenario
{
    public function key(): string
    {
        return 'investor.payments';
    }

    public function userTypes(): ?array
    {
        return [2];
    }

    public function collect(): array
    {
        $q = DB::table('accepted_bids as ab')->join('listings as l', 'l.id', '=', 'ab.business_id')->where('ab.investor_id', $this->user->id)->where('ab.status', 'awaiting_payment')->select('ab.id', 'l.name', 'ab.amount');

        return [$this->group('investments', $q, fn ($r) => $this->item('investor.payment', 'Your bid was accepted: complete payment', $r->name, 'dashboard.investor.dealroom', $r->id))];
    }
}
