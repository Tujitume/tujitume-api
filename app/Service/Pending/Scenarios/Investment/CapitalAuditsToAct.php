<?php

namespace App\Service\Pending\Scenarios\Investment;

use App\Service\Capital\CapitalAccess;
use App\Service\Organization\OrganizationPermission;
use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\DB;

/**
 * A capital provider (owner or super admin): a project manager audit fee to pay before the audit starts, and an audit
 * report that is in on a milestone not yet released (the provider decides the release).
 */
class CapitalAuditsToAct extends Scenario
{
    public function key(): string
    {
        return 'capital.audits';
    }

    public function userTypes(): ?array
    {
        return [5];
    }

    public function collect(): array
    {
        $ownerId = CapitalAccess::ownerId($this->user);
        if (! $ownerId || ! OrganizationPermission::isAdmin($this->user)) return [];

        $base = fn () => DB::table('capital_milestones as m')->join('startup_pitches as p', 'p.id', '=', 'm.app_id')
            ->where('p.capital_owner_id', $ownerId)
            ->where(fn ($w) => $w->whereNull('m.fund_released')->orWhere('m.fund_released', false))
            ->select('m.id', 'm.title', 'm.app_id', 'p.startup_name');
        $link = fn ($r) => 'dashboard.capitalOrg.investments?pitch=@'.$r->app_id.'&tab=funding';
        $subtitle = fn ($r) => trim($r->title.' · '.$r->startup_name, ' ·');

        return [
            $this->group('investments', $base()->where('m.audit_status', 'quoted'),
                fn ($r) => $this->item('capital.audit_fee', 'Audit fee to pay', $subtitle($r), $link($r), $r->id)),
            $this->group('investments', $base()->where('m.audit_status', 'reported'),
                fn ($r) => $this->item('capital.audit_report', 'Audit report in: decide the release', $subtitle($r), $link($r), $r->id)),
        ];
    }
}
