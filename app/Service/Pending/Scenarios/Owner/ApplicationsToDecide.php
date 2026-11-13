<?php

namespace App\Service\Pending\Scenarios\Owner;

use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\DB;

class ApplicationsToDecide extends Scenario
{
    public function key(): string
    {
        return 'owner.applications';
    }

    public function userTypes(): ?array
    {
        return [4];
    }

    public function collect(): array
    {
        // Single-round programs: a submitted application waits for the owner's decision. Multi-round programs are handled per round.
        $rows = DB::table('program_applications as a')->join('programs as p', 'p.id', '=', 'a.program_id')->where('p.user_id', $this->ownerId)->where('p.program_type', 'single_round')->where('a.status', 'pending')
            ->groupBy('p.id', 'p.program_title')
            ->select('p.id as program_id', 'p.program_title', DB::raw('count(*) as pending_count'))->get();

        return [[
            'group' => 'manage',
            'count' => (int) $rows->sum('pending_count'),
            'items' => $rows->take(self::MAX_ITEMS)->map(fn ($r) => $this->item(
                'owner.application',
                'Applications waiting for your decision',
                $r->program_title,
                'dashboard.programOrg.applicationsSingle::id='.$r->program_id.'?status=pending#round-applications',
                $r->program_id,
                null,
                (int) $r->pending_count,
            ))->all(),
        ]];
    }
}
