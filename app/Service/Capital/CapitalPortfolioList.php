<?php

namespace App\Service\Capital;

use App\Models\Auth\User;
use App\Models\Capital\CapitalMilestone;
use App\Models\Capital\StartupPitches;
use App\Service\Audits\CapitalAudits;
use App\Support\CollectionList;
use App\Support\ListShapes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * GET /capital/portfolio: the capital provider's portfolio (owner or any team member: the owner's), as a server list
 * (page, per_page, search, filters, sort, export). A funded business is an ACCEPTED application whose newest terms
 * agreement is accepted.
 *
 * Row: id (the application), capital_id, startup_name, sector, location, contact_person_name, contact_person_email, logo,
 *      offer_title, committed (the agreed amount; else the milestones' total; else the amount requested), released,
 *      to_release, progress (0-100), milestones_count, released_count, next_milestone {id, title, amount} | null,
 *      last_released_at, funded_at (when the terms were accepted), status (not_started | releasing | fully_released),
 *      audit_open (a project manager audit is open on one of its milestones), milestones [{id, title, amount, released,
 *      release_status, released_at, release_reference, audit_status, audit_recommendation, auditor}].
 * Filters: status, offer, sector, audit (yes|no: an audit is open), last_release (from~to), funded (from~to),
 *          committed (min~max); id for deep links.
 * Sort: funded (default -funded), name, committed, released, to_release, last_release.
 * Stats (over every funded business, not the page): deals, committed, released, to_release, fully_released, open_audits.
 */
class CapitalPortfolioList
{
    public const STATUSES = ['not_started', 'releasing', 'fully_released'];

    public function __construct(private User $user)
    {
    }

    public function respond(Request $request): JsonResponse
    {
        $all = $this->rows();
        $offers = $all->pluck('offer_title', 'capital_id')->mapWithKeys(fn ($t, $id) => [(string) $id => (string) $t])->all();

        $list = CollectionList::for($all, $request)
            ->search(fn ($r) => implode(' ', [$r['startup_name'], $r['contact_person_name'], $r['contact_person_email'], $r['sector'], $r['location'], $r['offer_title'], $r['next_milestone']['title'] ?? '']))
            ->filter('id', fn ($r) => $r['id'])
            ->options('status', fn ($r) => $r['status'], label: 'capital:provider.portfolio.filterStatus', values: self::STATUSES,
                optionLabels: [
                    'not_started' => 'capital:provider.portfolio.status.not_started',
                    'releasing' => 'capital:provider.portfolio.status.releasing',
                    'fully_released' => 'capital:provider.portfolio.status.fully_released',
                ], fallback: 'Status')
            ->options('offer', fn ($r) => (string) $r['capital_id'], label: 'app:capitalOffer',
                optionLabels: fn (array $ids) => array_intersect_key($offers, array_flip($ids)), fallback: 'Capital offer')
            ->options('sector', fn ($r) => (string) $r['sector'], label: 'app:sector', fallback: 'Sector')
            ->boolean('audit', fn ($r) => $r['audit_open'], label: 'capital:provider.portfolio.filterAudit', fallback: 'Audit open')
            ->dateRange('last_release', fn ($r) => (string) $r['last_released_at'], label: 'capital:provider.portfolio.filterLastRelease', fallback: 'Last release')
            ->dateRange('funded', fn ($r) => (string) $r['funded_at'], label: 'capital:provider.portfolio.filterFunded', fallback: 'Funded on')
            ->numberRange('committed', fn ($r) => $r['committed'], label: 'capital:provider.portfolio.colCommitted', fallback: 'Committed')
            ->sortable([
                'funded' => fn ($r) => (string) $r['funded_at'],
                'name' => fn ($r) => mb_strtolower((string) $r['startup_name']),
                'committed' => fn ($r) => (float) $r['committed'],
                'released' => fn ($r) => (float) $r['released'],
                'to_release' => fn ($r) => (float) $r['to_release'],
                'last_release' => fn ($r) => (string) $r['last_released_at'],
            ], default: '-funded');

        [$items, $page] = $list->fetch();
        $data = ListShapes::envelope($items, $page, $list->facets(['status', 'offer', 'sector']), $this->stats($all), $list->wantsExport())
            + ['filter_schema' => $list->filterSchema()];

        return response()->json(['data' => $data]);
    }

    public function rows(): Collection
    {
        $ownerId = CapitalAccess::ownerId($this->user);
        if (! $ownerId) return collect();

        $pitches = StartupPitches::with(['capital_offer:id,offer_title,user_id', 'latestAgreement', 'capital_milestones', 'sme:id,first_name,last_name,display_name,image'])
            ->where('capital_owner_id', $ownerId)->where('status', StartupPitches::STATUS_ACCEPTED)
            ->orderByDesc('id')->get()
            ->filter(fn (StartupPitches $p) => $p->latestAgreement?->status === 'accepted');

        $auditorIds = $pitches->flatMap(fn ($p) => $p->capital_milestones->pluck('auditor_id'))->filter()->unique();
        $auditors = User::whereIn('id', $auditorIds)->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return $pitches->map(fn (StartupPitches $p) => $this->row($p, $auditors))->values();
    }

    private function row(StartupPitches $p, Collection $auditors): array
    {
        $milestones = $p->capital_milestones->sortBy('id')->values();
        $isReleased = fn (CapitalMilestone $m) => $m->fund_released || $m->release_status === CapitalRelease::RELEASED || (int) $m->status === 1;
        $planned = round((float) $milestones->sum(fn ($m) => (float) $m->amount), 2);
        $agreed = (float) ($p->latestAgreement?->amount ?? 0);
        $committed = round($agreed > 0 ? $agreed : ($planned > 0 ? $planned : (float) $p->total_amount_requested), 2);
        $released = round((float) $milestones->filter($isReleased)->sum(fn ($m) => (float) $m->amount), 2);
        $next = $milestones->first(fn ($m) => ! $isReleased($m));
        $last = $milestones->filter($isReleased)->map(fn ($m) => $m->released_at)->filter()->max();
        $releasedCount = $milestones->filter($isReleased)->count();

        return [
            'id' => $p->id,
            'capital_id' => $p->capital_id,
            'startup_name' => $p->startup_name,
            'sector' => ListShapes::clean($p->sector),
            'location' => ListShapes::clean($p->headquarters_location),
            'contact_person_name' => $p->contact_person_name,
            'contact_person_email' => $p->contact_person_email,
            'logo' => $p->sme?->image,
            'offer_title' => $p->capital_offer?->offer_title,
            'committed' => $committed,
            'released' => $released,
            'to_release' => round(max(0, $committed - $released), 2),
            'progress' => $committed > 0 ? (int) min(100, round($released / $committed * 100)) : 0,
            'milestones_count' => $milestones->count(),
            'released_count' => $releasedCount,
            'next_milestone' => $next ? ['id' => $next->id, 'title' => $next->title, 'amount' => (float) $next->amount, 'audit_status' => $next->audit_status] : null,
            'last_released_at' => $last ? Carbon::parse($last)->toIso8601String() : null,
            'funded_at' => ($p->latestAgreement?->updated_at ?? $p->updated_at)?->toIso8601String(),
            'status' => $milestones->isNotEmpty() && ! $next ? 'fully_released' : ($releasedCount > 0 ? 'releasing' : 'not_started'),
            'audit_open' => $milestones->contains(fn ($m) => CapitalAudits::isOpen($m)),
            'milestones' => $milestones->map(fn (CapitalMilestone $m) => [
                'id' => $m->id,
                'title' => $m->title,
                'amount' => (float) $m->amount,
                'released' => $isReleased($m),
                'release_status' => $m->release_status,
                'released_at' => $m->released_at?->toIso8601String(),
                'release_reference' => $m->release_reference,
                'audit_status' => $m->audit_status,
                'audit_recommendation' => $m->audit_recommendation,
                'auditor' => ($a = $auditors->get($m->auditor_id)) ? trim($a->first_name.' '.$a->last_name) : null,
            ])->all(),
        ];
    }

    private function stats(Collection $rows): array
    {
        return [
            'deals' => $rows->count(),
            'committed' => round((float) $rows->sum('committed'), 2),
            'released' => round((float) $rows->sum('released'), 2),
            'to_release' => round((float) $rows->sum('to_release'), 2),
            'fully_released' => $rows->where('status', 'fully_released')->count(),
            'open_audits' => $rows->where('audit_open', true)->count(),
        ];
    }
}
