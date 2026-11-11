<?php

namespace App\Service\Pending\Scenarios\Owner;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class OrdersDelivered extends Scenario
{
    public function key(): string
    {
        return 'owner.orders_delivered';
    }

    public function userTypes(): ?array
    {
        return [4];
    }

    public function collect(): array
    {
        $q = DB::table('reviewer_orders as o')->join('programs as p', 'p.id', '=', 'o.program_id')->where('p.user_id', $this->ownerId)->where('o.work_status', 'delivered')
            ->select('o.id', 'p.program_title', 'o.order_type', 'o.delivered_at');

        return [$this->group('manage', $q, fn ($r) => $this->item('owner.order_delivered', 'Work delivered for approval', $r->program_title, 'dashboard.grantOrg.orders?tab=delivered#order-'.$r->id, $r->id, $this->date($r->delivered_at)))];
    }
}
