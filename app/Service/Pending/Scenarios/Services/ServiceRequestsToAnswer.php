<?php

namespace App\Service\Pending\Scenarios\Services;

use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\DB;

/** A service provider shared a service with my business, program or capital offer and waits for my answer */
class ServiceRequestsToAnswer extends Scenario
{
    public function key(): string
    {
        return 'services.requests';
    }

    public function userTypes(): ?array
    {
        // The owners of what providers can write to: businesses (1), program organizations (4), capital (5)
        return [1, 4, 5];
    }

    public function collect(): array
    {
        $waiting = DB::table('service_proposals as p')
            ->join('services as s', 's.id', '=', 'p.service_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.provider_id')
            ->where('p.recipient_id', $this->ownerId)->where('p.status', 'pending')
            ->orderByDesc('p.created_at')
            ->select('p.id', 'p.target_name', 's.name as service', 'u.first_name', 'u.last_name');

        return [
            $this->group('services', $waiting, fn ($r) => $this->item(
                'service.request',
                'A service provider wants to work with you',
                trim(($r->first_name ?? '').' '.($r->last_name ?? '')).' · '.$r->service.($r->target_name ? ' · '.$r->target_name : ''),
                $this->home().'.serviceRequests?request=@'.$r->id,
                $r->id,
            )),
        ];
    }
}
