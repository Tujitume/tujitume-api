<?php

namespace App\Service\Pending\Scenarios\Review;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class ModificationRequested extends Scenario
{
    public function key(): string
    {
        return 'review.modification';
    }

    public function userTypes(): ?array
    {
        return [3, 4, 6, 7];
    }

    public function collect(): array
    {
        $q = DB::table('reviewer_orders as o')->leftJoin('programs as p', 'p.id', '=', 'o.program_id')->where('o.reviewer_id', $this->user->id)->where('o.work_status', 'modification_requested')
            ->select('o.id', 'p.program_title', 'o.order_type', 'o.deadline');
        $link = match ($this->type) { 3 => 'dashboard.serviceProvider.programWork.orders', 4 => 'dashboard.grantOrg.orders', default => 'dashboard.reviewerGrantOrg.orders' };

        return [$this->group('review', $q, fn ($r) => $this->item('review.modification', 'Changes requested on your work', $r->program_title, $link.'?deliver=@'.$r->id.'#order-'.$r->id, $r->id, $this->date($r->deadline)))];
    }
}
