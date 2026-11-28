<?php

namespace App\Service\Capital;

use App\Models\Auth\User;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\StartupPitches;
use App\Support\CollectionList;
use App\Support\ListShapes;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The capital offers a business owner applied to (My Capital Applications, stage "all") or was approved for (the Capital
 * Deal Room, stage "approved"), as a server list. A row is the offer with the person's application on it (applied,
 * application_status, applied_at, application_id, amount_requested: the same fields /capital/capital-offers adds), plus
 * `group` (pending | approved | rejected: the newest application on the offer) and `timing` (open | closingSoon | upcoming | closed).
 *
 * The rows come from two tables and an offer holds several sectors / regions / stages, so they are filtered and paged in
 * memory (CollectionList); one person has few applications. Only visible offers are listed, as the old pages did.
 * Filters (all in `filter_schema`): status (stage "all" only), timing, sector / region / stage (any of the offer's),
 * provider, requested / allocation / pool (min~max), stillOpen (yes|no), applied / ends (from~to); id for deep links.
 */
class MyCapitalApplicationList
{
    private const DAY = 86400;
    // "Closing soon": the last 7 days, the same as programs (ProgramDiscoverList::SOON_DAYS)
    private const SOON = 7 * self::DAY;

    /** Filters on a list value (an offer has several): key => row field. */
    private const MULTI = ['sector' => 'sectors', 'region' => 'regions', 'stage' => 'startup_stage'];
    private const MULTI_LABELS = ['sector' => 'app:sector', 'region' => 'app:region', 'stage' => 'app:stage'];

    public function __construct(private User $user, private string $stage)
    {
    }

    public function respond(Request $request): JsonResponse
    {
        $all = $this->rows();
        if ($this->stage === 'approved') {
            $all = $all->where('group', 'approved')->values();
        }

        $list = $this->list($all, $request);
        [$items, $page] = $list->fetch();

        // `facets` stays for older screens (each value once per offer); the panels read filter_schema
        $facets = $list->facets(['status', ...array_keys(self::MULTI)]);

        $data = ListShapes::envelope($items, $page, $facets, $this->stats($all), $request->boolean('export'))
            + ['filter_schema' => $list->filterSchema()];

        return response()->json(['capital' => $data['items'], 'data' => $data]);
    }

    private function list(Collection $rows, Request $request): CollectionList
    {
        $providers = self::providerNames($rows->pluck('user_id')->filter()->unique()->all());
        $list = CollectionList::for($rows, $request)
            ->search(fn ($r) => implode(' ', [$r['offer_title'], ...$r['sectors'], ...$r['regions']]))
            ->filter('id', fn ($r) => $r['id']);
        // Described filters: the panels of both pages, their chips and URL state come from `filter_schema`
        if ($this->stage !== 'approved') { // the Deal Room lists approved offers only
            $list->options('status', fn ($r) => $r['group'], label: 'app:status', values: ['pending', 'approved', 'rejected'],
                optionLabels: ['pending' => 'app:pending2', 'approved' => 'app:approved', 'rejected' => 'app:rejected']);
        }
        $list->options('timing', fn ($r) => $r['timing'], label: 'entrepreneur:capitalList.availability', values: ['open', 'closingSoon', 'upcoming', 'closed'],
            optionLabels: ['open' => 'app:open', 'closingSoon' => 'app:closingSoon', 'upcoming' => 'entrepreneur:capitalList.upcoming', 'closed' => 'app:closed'], fallback: 'Availability');
        // an offer lists several sectors / regions / stages: it matches when any of them is picked
        foreach (self::MULTI as $key => $field) {
            $list->options($key, fn ($r) => array_values(array_unique($r[$field])), label: self::MULTI_LABELS[$key]);
        }

        return $list
            ->options('provider', fn ($r) => (string) $r['user_id'], label: 'app:entFilterCapitalProvider',
                optionLabels: fn (array $ids) => array_intersect_key($providers, array_flip($ids)), fallback: 'Capital provider')
            ->numberRange('requested', fn ($r) => $r['amount_requested'], label: 'app:amountRequested2')
            ->numberRange('allocation', fn ($r) => $r['per_startup_allocation'], label: 'app:allocation')
            ->numberRange('pool', fn ($r) => $r['total_capital_available'], label: 'app:totalCapital')
            ->boolean('stillOpen', fn ($r) => in_array($r['timing'], ['open', 'closingSoon'], true), label: 'app:entFilterStillOpen', fallback: 'Still open')
            ->dateRange('applied', fn ($r) => (string) $r['applied_at'], label: 'app:appliedOn')
            ->dateRange('ends', fn ($r) => self::day($r['end_date']), label: 'entrepreneur:capitalList.endDate', presets: ListShapes::DUE_PRESETS, fallback: 'End date')
            ->sortable([
                'title' => fn ($r) => mb_strtolower((string) $r['offer_title']),
                'allocation' => fn ($r) => (float) $r['per_startup_allocation'],
                'total' => fn ($r) => (float) $r['total_capital_available'],
                'applied' => fn ($r) => (string) $r['applied_at'],
                'ends' => fn ($r) => self::day($r['end_date']),
            ], default: '-applied');
    }

    /** The capital provider's organization (or name) of each offer owner id. */
    public static function providerNames(array $ids): array
    {
        return User::with('organization')->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (User $u) => [(string) $u->id => $u->organization?->display_name ?: $u->organization?->name ?: trim($u->first_name.' '.$u->last_name)])
            ->all();
    }

    /** Every visible offer the person (or their organization's owner) applied to, with their latest application on it. */
    private function rows(): Collection
    {
        $ownerIds = array_values(array_unique([$this->user->id, $this->user->organizationOwnerId()]));
        $mine = StartupPitches::whereIn('user_id', $ownerIds)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'capital_id', 'status', 'created_at', 'total_amount_requested'])
            ->unique('capital_id')
            ->keyBy('capital_id');

        $now = now()->getTimestamp();

        return CapitalOffer::whereIn('id', $mine->keys())->where('visible', 1)->get()
            ->map(function (CapitalOffer $offer) use ($mine, $now) {
                $pitch = $mine->get($offer->id);
                $status = StartupPitches::statusName($pitch->status);

                return [
                    ...$offer->toArray(),
                    'sectors' => self::asList($offer->sectors),
                    'regions' => self::asList($offer->regions),
                    'startup_stage' => self::asList($offer->startup_stage),
                    'applied' => true,
                    'application_id' => $pitch->id,
                    'application_status' => $status,
                    'applied_at' => $pitch->created_at?->toDateTimeString(),
                    'amount_requested' => $pitch->total_amount_requested,
                    'group' => $status,
                    'timing' => self::timing($offer->start_date, $offer->end_date, $now),
                ];
            })
            ->values();
    }

    /** Totals for the cards, over every row of the tab (not only the page or the search). */
    private function stats(Collection $rows): array
    {
        $allocation = fn (Collection $r) => round((float) $r->sum(fn ($x) => (float) $x['per_startup_allocation']), 2);
        $approved = $rows->where('group', 'approved');
        $monthAgo = now()->subDays(30)->toDateTimeString();

        if ($this->stage === 'approved') {
            $amounts = $rows->map(fn ($x) => (float) $x['per_startup_allocation']);
            $closed = $rows->where('timing', 'closed')->count();

            return [
                'total' => $rows->count(),
                'sectors' => $rows->flatMap(fn ($x) => $x['sectors'])->unique()->count(),
                'regions' => $rows->flatMap(fn ($x) => $x['regions'])->unique()->count(),
                'allocation_total' => $allocation($rows),
                'allocation_average' => $rows->count() ? round($amounts->avg(), 2) : null,
                'allocation_largest' => $rows->count() ? round($amounts->max(), 2) : null,
                'pool_total' => round((float) $rows->sum(fn ($x) => (float) $x['total_capital_available']), 2),
                'open' => $rows->count() - $closed,
                'closed' => $closed,
            ];
        }

        $appliedIds = $rows->pluck('id')->all();
        $now = now()->getTimestamp();
        $openToApply = CapitalOffer::where('visible', 1)->whereNotIn('id', $appliedIds)->get(['id', 'start_date', 'end_date'])
            ->filter(fn ($o) => in_array(self::timing($o->start_date, $o->end_date, $now), ['open', 'closingSoon'], true))
            ->count();

        return [
            'total' => $rows->count(),
            'pending' => $rows->where('group', 'pending')->count(),
            'approved' => $approved->count(),
            'rejected' => $rows->where('group', 'rejected')->count(),
            'allocation_total' => $allocation($rows),
            'approved_allocation' => $allocation($approved),
            'last_applied' => $rows->max('applied_at'),
            'last_30_days' => $rows->filter(fn ($x) => (string) $x['applied_at'] >= $monthAgo)->count(),
            'open_to_apply' => $openToApply,
        ];
    }

    /** open → closingSoon (ends within 7 days) → closed; upcoming = starts later (as capitalData.js). */
    public static function timing(mixed $start, mixed $end, int $now): string
    {
        $start = self::timestamp($start);
        $end = self::timestamp($end);
        if ($start && $start > $now) {
            return 'upcoming';
        }
        if ($end) {
            if ($end + self::DAY <= $now) {
                return 'closed';
            }
            if ($end - $now <= self::SOON) {
                return 'closingSoon';
            }
        }

        return 'open';
    }

    public static function timestamp(mixed $date): ?int
    {
        if (! $date) {
            return null;
        }
        try {
            return Carbon::parse($date)->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function day(mixed $date): string
    {
        $ts = self::timestamp($date);

        return $ts ? date('Y-m-d', $ts) : '';
    }

    public static function asList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }

        return array_values(array_filter((array) $value, fn ($v) => $v !== null && $v !== ''));
    }
}
