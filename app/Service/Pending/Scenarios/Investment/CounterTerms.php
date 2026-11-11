<?php

namespace App\Service\Pending\Scenarios\Investment;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class CounterTerms extends Scenario
{
    public function key(): string
    {
        return 'capital.terms';
    }

    public function userTypes(): ?array
    {
        return [5];
    }

    public function collect(): array
    {
        $q = DB::table('capital_terms_agreements')->where('capital_owner_id', $this->user->id)->where('status', 'counter_submitted')->select('id');

        return [$this->group('investments', $q, fn ($r) => $this->item('capital.counter', 'Counter terms to answer', null, 'dashboard.capitalOrg.investments', $r->id))];
    }
}
