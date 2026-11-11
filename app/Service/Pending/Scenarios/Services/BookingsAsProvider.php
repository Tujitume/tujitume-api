<?php

namespace App\Service\Pending\Scenarios\Services;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class BookingsAsProvider extends Scenario
{
    public function key(): string
    {
        return 'services.provider';
    }

    public function userTypes(): ?array
    {
        return [1, 2, 3, 4, 5];
    }

    public function collect(): array
    {
        $link = $this->bookingsLink(true);
        $pending = DB::table('service_bookings as b')->join('services as s', 's.id', '=', 'b.service_id')->where('b.service_owner_id', $this->user->id)->where('b.status', 'pending')->select('b.id', 's.name', 'b.date');
        $offers = DB::table('service_offers as o')->join('services as s', 's.id', '=', 'o.service_id')->where('s.user_id', $this->user->id)->where('o.status', 'pending')->select('o.id', 's.name', 'o.offered_price');

        return [
            $this->group('services', $pending, fn ($r) => $this->item('service.confirm', 'New booking to confirm', $r->name, $link, $r->id, $this->date($r->date))),
            $this->group('services', $offers, fn ($r) => $this->item('service.offer', 'Price offer to answer', $r->name, $link, $r->id)),
        ];
    }
}
