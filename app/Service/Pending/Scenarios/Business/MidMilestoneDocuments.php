<?php

namespace App\Service\Pending\Scenarios\Business;

use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\DB;

/** A funded milestone is 30% through its execution time: the business owner must upload mid-milestone documents. */
class MidMilestoneDocuments extends Scenario
{
    public function key(): string
    {
        return 'business.mid_docs';
    }

    public function userTypes(): ?array
    {
        return [1];
    }

    public function collect(): array
    {
        // The same rule the milestone reminder uses: 75% released, 30% of the execution time gone, mid-milestone not started
        $rows = DB::table('milestones as m')->join('listings as l', 'l.id', '=', 'm.listing_id')->where('m.user_id', $this->user->id)
            ->where('m.fund_released_75', 1)->where(fn ($w) => $w->whereNull('m.mid_milestone_started')->orWhere('m.mid_milestone_started', 0))
            ->whereNotNull('m.exec_deadline_date')->whereNotIn('m.status', ['done', 'admin_review', 'execution_submitted'])
            ->select('m.id', 'm.title', 'm.n_o_days', 'm.exec_deadline_date', 'l.name')->get()
            ->filter(function ($r) {
                $deadline = \Illuminate\Support\Carbon::parse($r->exec_deadline_date);
                if ($deadline->isPast()) return false;
                $total = max(1, (int) $r->n_o_days * 24);
                $passed = max(0, $total - now()->diffInHours($deadline, false));

                return ($passed / $total) >= 0.3;
            })->values();

        return [['group' => 'investments', 'count' => $rows->count(), 'items' => $rows->take(self::MAX_ITEMS)->map(fn ($r) => $this->item('business.mid_docs', 'Upload your mid-milestone progress documents', $r->name.' · '.$r->title, 'dashboard.entrepreneur.milestones', $r->id, $this->date($r->exec_deadline_date)))->all()]];
    }
}
