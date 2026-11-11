<?php

namespace App\Service\Pending\Scenarios\Services;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class BookingsAsCustomer extends Scenario
{
    public function key(): string
    {
        return 'services.customer';
    }

    public function userTypes(): ?array
    {
        return [2, 3, 4, 5];
    }

    public function collect(): array
    {
        $link = $this->bookingsLink(false);
        $unpaid = DB::table('service_bookings as b')->join('services as s', 's.id', '=', 'b.service_id')->where('b.booker_id', $this->user->id)->where('b.status', 'confirmed')->where(fn ($w) => $w->whereNull('b.paid')->orWhere('b.paid', 0))
            ->select('b.id', 's.name', 'b.date');
        $release = DB::table('service_booking_milestones as m')->join('services as s', 's.id', '=', 'm.service_id')->where('m.booker_id', $this->user->id)->where('m.status', 'Done')->where(fn ($w) => $w->whereNull('m.released')->orWhere('m.released', '!=', 1))
            ->select('m.id', 'm.title', 's.name');
        $countered = DB::table('service_offers as o')->join('services as s', 's.id', '=', 'o.service_id')->where('o.booker_id', $this->user->id)->where('o.status', 'countered')->select('o.id', 's.name', 'o.counter_price');

        return [
            $this->group('services', $unpaid, fn ($r) => $this->item('service.pay', 'Booking confirmed: pay to secure it', $r->name, $link, $r->id, $this->date($r->date))),
            $this->group('services', $release, fn ($r) => $this->item('service.release', 'Work finished: release the payment', $r->name.' · '.$r->title, $link, $r->id)),
            $this->group('services', $countered, fn ($r) => $this->item('service.countered', 'The provider sent a counter offer', $r->name, $link, $r->id)),
        ];
    }
}
