<?php

namespace App\Service\Pending\Scenarios\Review;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class SiteVisitRequests extends Scenario
{
    public function key(): string
    {
        return 'review.site_visit';
    }

    public function userTypes(): ?array
    {
        return [3, 4, 6, 7];
    }

    public function collect(): array
    {
        $q = DB::table('m_e_site_visits as v')->join('m_e_checkpoints as c', 'c.id', '=', 'v.checkpoint_id')->join('reviewer_orders as o', 'o.site_visit_id', '=', 'v.id')
            ->where('v.reviewer_id', $this->user->id)->where('v.status', 'scheduled')->whereNotNull('c.sent_at')->where('o.acceptance_status', 'pending')
            ->select('v.id', 'v.objective', 'v.location', 'v.start_date');

        return [$this->group('review', $q, fn ($r) => $this->item('review.site_visit', 'Site visit request', $r->objective ?: $r->location, $this->drawer('siteVisit'), $r->id, $this->date($r->start_date)))];
    }
}
