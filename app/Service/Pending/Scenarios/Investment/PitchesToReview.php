<?php

namespace App\Service\Pending\Scenarios\Investment;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class PitchesToReview extends Scenario
{
    public function key(): string
    {
        return 'capital.pitches';
    }

    public function userTypes(): ?array
    {
        return [5];
    }

    public function collect(): array
    {
        $q = DB::table('startup_pitches')->where('capital_owner_id', $this->user->id)->where('status', 0)->select('id', 'startup_name');

        return [$this->group('investments', $q, fn ($r) => $this->item('capital.pitch', 'Startup pitch to review', $r->startup_name, 'dashboard.capitalOrg.pitch', $r->id))];
    }
}
