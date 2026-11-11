<?php

namespace App\Service\Pending\Scenarios\Business;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class TermsToAnswer extends Scenario
{
    public function key(): string
    {
        return 'business.terms';
    }

    public function userTypes(): ?array
    {
        return [1];
    }

    public function collect(): array
    {
        $q = DB::table('capital_terms_agreements')->where('business_owner_id', $this->user->id)->where('status', 'submitted')->select('id', 'amount');

        return [$this->group('investments', $q, fn ($r) => $this->item('business.terms', 'Funding terms to accept or reject', null, 'dashboard.entrepreneur.capitalDealroom', $r->id))];
    }
}
