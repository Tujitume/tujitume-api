<?php

namespace App\Service\Capital;

use App\Models\Auth\User;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\StartupPitches;
use App\Models\Shared\Like;
use App\Models\Shared\Watchlist;
use App\Support\CollectionList;
use App\Support\ListShapes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * /capital/capital-offers as a server list for business owners (Explore Capital): every visible offer, with search,
 * filters, sort, page and export. A row is the offer as the full list sends it (pitch_count, owner_website, liked and the
 * person's application: applied, application_status, applied_at, application_id, amount_requested) plus `saved` (in their
 * watchlist) and `timing` (open | closingSoon | upcoming | closed, as MyCapitalApplicationList).
 *
 * An offer holds several sectors / regions / stages (JSON lists), so the rows are filtered in memory (CollectionList); the
 * offers are few. Filters (all in `filter_schema`): timing, sector / region / stage (any of the offer's), provider,
 * allocation / pool (min~max), applied / saved (yes|no), ends / starts (from~to); id for deep links.
 */
class CapitalDiscoverList
{
    private const MULTI = ['sector' => 'sectors', 'region' => 'regions', 'stage' => 'startup_stage'];
    private const MULTI_LABELS = ['sector' => 'app:sector', 'region' => 'app:region', 'stage' => 'app:stage'];

    /**
     * @param  int|null  $ownerId  a capital provider's own offers (every one, hidden too, with `visible` and
     *                              `in_my_watchlist`) instead of the visible offers of everyone
     */
    public function __construct(private User $user, private ?int $ownerId = null)
    {
    }

    public function respond(Request $request): JsonResponse
    {
        $all = $this->rows();
        $providers = MyCapitalApplicationList::providerNames($all->pluck('user_id')->filter()->unique()->all());

        $list = CollectionList::for($all, $request)
            ->search(fn ($r) => implode(' ', [$r['offer_title'], $r['impact_objectives'] ?? '', $providers[(string) $r['user_id']] ?? '', ...$r['sectors'], ...$r['regions'], ...$r['startup_stage']]))
            ->filter('id', fn ($r) => $r['id'])
            ->options('timing', fn ($r) => $r['timing'], label: 'entrepreneur:capitalList.availability', values: ['open', 'closingSoon', 'upcoming', 'closed'],
                optionLabels: ['open' => 'app:open', 'closingSoon' => 'app:closingSoon', 'upcoming' => 'entrepreneur:capitalList.upcoming', 'closed' => 'app:closed'], fallback: 'Availability');
        foreach (self::MULTI as $key => $field) {
            $list->options($key, fn ($r) => array_values(array_unique($r[$field])), label: self::MULTI_LABELS[$key]);
        }
        $list
            ->options('provider', fn ($r) => (string) $r['user_id'], label: 'app:entFilterCapitalProvider',
                optionLabels: fn (array $ids) => array_intersect_key($providers, array_flip($ids)), searchable: true, fallback: 'Capital provider')
            ->numberRange('allocation', fn ($r) => $r['per_startup_allocation'], label: 'app:allocation')
            ->numberRange('pool', fn ($r) => $r['total_capital_available'], label: 'app:totalCapital')
            ->boolean('applied', fn ($r) => $r['applied'], label: 'app:entFilterAppliedByMe', fallback: 'Applied by me')
            ->boolean('saved', fn ($r) => $r['saved'], label: 'app:watchlisted')
            ->dateRange('ends', fn ($r) => MyCapitalApplicationList::day($r['end_date']), label: 'entrepreneur:capitalList.endDate', presets: ListShapes::DUE_PRESETS, fallback: 'End date')
            ->dateRange('starts', fn ($r) => MyCapitalApplicationList::day($r['start_date']), label: 'entrepreneur:grants.discoverPage.startDate', presets: ListShapes::DUE_PRESETS, fallback: 'Start date')
            ->sortable([
                'newest' => fn ($r) => (string) $r['created_at'],
                'title' => fn ($r) => mb_strtolower((string) $r['offer_title']),
                'allocation' => fn ($r) => (float) $r['per_startup_allocation'],
                'total' => fn ($r) => (float) $r['total_capital_available'],
                'ends' => fn ($r) => MyCapitalApplicationList::day($r['end_date']) ?: '9999-12-31',
                'applications' => fn ($r) => (int) $r['pitch_count'],
            ], default: '-newest');

        // the provider's own list: shown / hidden
        if ($this->ownerId !== null) {
            $list->options('visible', fn ($r) => (int) $r['visible'] === 1 ? 'shown' : 'hidden', label: 'app:visibility',
                values: ['shown', 'hidden'], optionLabels: ['shown' => 'app:shown', 'hidden' => 'app:hidden'], fallback: 'Visibility');
        }

        [$items, $page] = $list->fetch();
        $facets = $list->facets(array_keys(self::MULTI));
        $data = ListShapes::envelope($items, $page, $facets, $this->stats($all), $list->wantsExport()) + ['filter_schema' => $list->filterSchema()];

        // `capital` keeps the old top-level key
        return response()->json(['capital' => $data['items'], 'data' => $data]);
    }

    /** Every visible offer, with the same extras the full list adds, in a few queries. */
    private function rows(): Collection
    {
        $offers = CapitalOffer::with('owner:id,website')->withCount('liked')
            ->when($this->ownerId !== null, fn ($q) => $q->where('user_id', $this->ownerId), fn ($q) => $q->where('visible', 1))
            ->latest()->get();
        $ids = $offers->pluck('id')->all();
        $pitches = StartupPitches::whereIn('capital_id', $ids)->notRejected()->selectRaw('capital_id, count(*) as n')->groupBy('capital_id')->pluck('n', 'capital_id');
        $liked = Like::where('type', 'capital')->where('user_id', $this->user->id)->whereIn('listing_id', $ids)->pluck('listing_id')->map(fn ($id) => (int) $id)->all();
        $saved = Watchlist::where('user_id', $this->ownerId ?? $this->user->id)->where('org_type', 'capital')->pluck('org_id')->map(fn ($id) => (int) $id)->all();
        $ownerIds = array_values(array_unique(array_filter([$this->user->id, $this->user->organizationOwnerId()])));
        $mine = StartupPitches::whereIn('capital_id', $ids)->whereIn('user_id', $ownerIds)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'capital_id', 'status', 'created_at', 'total_amount_requested'])
            ->unique('capital_id')->keyBy('capital_id');
        $now = now()->getTimestamp();

        return $offers->map(function (CapitalOffer $offer) use ($pitches, $liked, $saved, $mine, $now) {
            $pitch = $mine->get($offer->id);
            $website = $offer->owner?->website;
            $offer->unsetRelation('owner');

            return [
                ...$offer->toArray(),
                'sectors' => MyCapitalApplicationList::asList($offer->sectors),
                'regions' => MyCapitalApplicationList::asList($offer->regions),
                'startup_stage' => MyCapitalApplicationList::asList($offer->startup_stage),
                'pitch_count' => (int) ($pitches[$offer->id] ?? 0),
                'owner_website' => $website,
                'liked' => in_array((int) $offer->id, $liked, true),
                'saved' => in_array((int) $offer->id, $saved, true),
                'in_my_watchlist' => in_array((int) $offer->id, $saved, true),
                'applied' => (bool) $pitch,
                'application_id' => $pitch?->id,
                'application_status' => $pitch ? StartupPitches::statusName($pitch->status) : null,
                'applied_at' => $pitch?->created_at,
                'amount_requested' => $pitch?->total_amount_requested,
                'timing' => MyCapitalApplicationList::timing($offer->start_date, $offer->end_date, $now),
            ];
        })->values();
    }

    /** The header cards, over every visible offer (not only the page or the search). */
    private function stats(Collection $rows): array
    {
        $allocations = $rows->map(fn ($r) => (float) $r['per_startup_allocation'])->filter(fn ($v) => $v > 0);
        $monthAgo = now()->subDays(30)->getTimestamp();

        return [
            'total' => $rows->count(),
            'open' => $rows->whereIn('timing', ['open', 'closingSoon'])->count(),
            'closing_soon' => $rows->where('timing', 'closingSoon')->count(),
            'sectors' => $rows->flatMap(fn ($r) => $r['sectors'])->unique()->count(),
            'regions' => $rows->flatMap(fn ($r) => $r['regions'])->unique()->count(),
            'pool_total' => round((float) $rows->sum(fn ($r) => (float) $r['total_capital_available']), 2),
            'allocation_average' => $allocations->count() ? round($allocations->avg(), 2) : null,
            'allocation_largest' => $allocations->count() ? round($allocations->max(), 2) : null,
            'saved' => $rows->where('saved', true)->count(),
            // money and applications over every offer listed (all the owner's offers in the provider's own list)
            'total_capital' => round((float) $rows->sum(fn ($r) => (float) $r['total_capital_available']), 2),
            'available_total' => round((float) $rows->sum(fn ($r) => (float) ($r['available_amount'] ?? 0)), 2),
            'applications_total' => (int) $rows->sum(fn ($r) => (int) $r['pitch_count']),
            'hidden' => $rows->filter(fn ($r) => (int) ($r['visible'] ?? 1) !== 1)->count(),
            'new_this_month' => $rows->filter(fn ($r) => (MyCapitalApplicationList::timestamp($r['created_at']) ?? 0) >= $monthAgo)->count(),
        ];
    }
}
