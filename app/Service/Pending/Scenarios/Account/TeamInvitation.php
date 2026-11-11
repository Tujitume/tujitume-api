<?php

namespace App\Service\Pending\Scenarios\Account;

use App\Service\Pending\Scenario;
use App\Service\Program\RoundParticipation;
use Illuminate\Support\Facades\DB;

class TeamInvitation extends Scenario
{
    public function key(): string
    {
        return 'account.team_invitation';
    }

    public function userTypes(): ?array
    {
        return null;
    }

    public function collect(): array
    {
        $q = DB::table('organization_user_roles as r')->join('organizations as o', 'o.id', '=', 'r.organization_id')
            ->where('r.user_id', $this->user->id)->where('r.status', 'pending')
            ->where(fn ($w) => $w->whereNull('r.invitation_expires_at')->orWhere('r.invitation_expires_at', '>', now()))
            ->select('r.id', 'o.name', 'r.invited_at', 'r.invitation_expires_at');

        return [$this->group('account', $q, fn ($r) => $this->item('team.invitation', 'Invitation to join an organization', $r->name ?: 'Accept or decline it from your invitation email.', $this->home().'?requests=1', $r->id, $this->date($r->invitation_expires_at)))];
    }
}
