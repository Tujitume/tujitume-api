<?php

namespace App\Service\Business;

use App\Models\Business\Listing;
use App\Support\ListQuery;
use App\Support\ListShapes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A business owner's own businesses (My Businesses, the Deal Room list) as a server list: search, filters, sort, page and
 * export on the database, option counts for the filter popover and totals over ALL of their businesses for the header.
 *
 * deal: where a business stands with investors — hidden (not active), targetMet (active, threshold met) or open.
 * Filters (all in `filter_schema`): status, deal, industry, stage, location, goal / raised / funded (%) / investors
 * (min~max), targetMet / hasInvestors / hasMilestones (yes|no), created (from~to); id for deep links.
 */
class MyListingList
{
    private const STATUS_SQL = "CASE WHEN listings.active = 1 THEN 'active' ELSE 'inactive' END";
    private const DEAL_SQL = "CASE WHEN listings.active = 1 THEN (CASE WHEN listings.threshold_met = 1 THEN 'targetMet' ELSE 'open' END) ELSE 'hidden' END";
    /** Share of the goal raised, in percent; a business without a goal counts as 0%. */
    private const FUNDED_SQL = 'CASE WHEN COALESCE(listings.investment_needed, 0) > 0 THEN listings.amount_collected * 100.0 / listings.investment_needed ELSE 0 END';

    public function __construct(private int $userId)
    {
    }

    public function respond(Request $request): JsonResponse
    {
        $base = $this->base();
        ListShapes::extraSort($base, $request, [
            // share of the goal raised; a business without a goal sorts as 0%
            'funded' => fn (Builder $q, string $dir) => $q->orderByRaw('CASE WHEN COALESCE(listings.investment_needed, 0) > 0 THEN listings.amount_collected / listings.investment_needed ELSE 0 END '.$dir),
        ]);

        $list = ListQuery::for($base, $request)
            ->search(['name', 'category', 'location'])
            ->filter('id', 'id')
            // Described filters: the My Businesses and Deal Room panels, chips and URL state come from `filter_schema`
            ->options('status', DB::raw(self::STATUS_SQL), label: 'app:status', values: ['active', 'inactive'],
                optionLabels: ['active' => 'app:active', 'inactive' => 'app:inactive'])
            ->options('deal', DB::raw(self::DEAL_SQL), label: 'entrepreneur:dealroomList.dealStatus', values: ['open', 'targetMet', 'hidden'], fallback: 'Deal status')
            ->options('industry', 'category', label: 'app:industry')
            ->options('stage', 'stage', label: 'app:stage')
            ->options('location', ListShapes::derivedFilter($this->base(), ['location'], fn ($l) => ListShapes::place($l->location)),
                label: 'app:location', countBy: ListShapes::derivedCount(['location'], fn ($l) => ListShapes::place($l->location)))
            ->numberRange('goal', 'investment_needed', label: 'app:goal')
            ->numberRange('raised', 'amount_collected', label: 'entrepreneur:dealroomList.raisedCol', fallback: 'Raised')
            ->numberRange('funded', DB::raw(self::FUNDED_SQL), label: 'app:entFilterFundedPct', fallback: 'Funded (%)')
            ->numberRange('investors', DB::raw('COALESCE(listings.invest_count, 0)'), label: 'app:entFilterInvestorCount', fallback: 'Number of investors')
            ->boolean('targetMet', fn (Builder $q, bool $yes) => $yes
                ? $q->where('listings.threshold_met', 1)
                : $q->where(fn (Builder $n) => $n->whereNull('listings.threshold_met')->orWhere('listings.threshold_met', '!=', 1)),
                label: 'app:targetMet')
            ->boolean('hasInvestors', fn (Builder $q, bool $yes) => $yes
                ? $q->where('listings.invest_count', '>', 0)
                : $q->where(fn (Builder $n) => $n->whereNull('listings.invest_count')->orWhere('listings.invest_count', '<=', 0)),
                label: 'app:entFilterHasInvestors', fallback: 'Has investors')
            ->boolean('hasMilestones', fn (Builder $q, bool $yes) => $yes ? $q->whereHas('milestones') : $q->whereDoesntHave('milestones'),
                label: 'app:entFilterHasMilestones', fallback: 'Has milestones')
            ->dateRange('created', 'created_at', label: 'app:created')
            ->sortable(['name' => 'name', 'created' => 'created_at', 'investors' => 'invest_count', 'raised' => 'amount_collected', 'goal' => 'investment_needed'], default: '-created');

        [$rows, $page] = $list->fetch();
        $rows->each(fn (Listing $l) => $l->deal = $this->dealOf($l));

        // `facets` stays for older screens; the panels read filter_schema
        $facets = $list->facets(['industry', 'stage', 'status', 'deal', 'location']);

        $data = ListShapes::envelope($rows, $page, $facets, $this->stats(), $list->wantsExport()) + ['filter_schema' => $list->filterSchema()];

        // `business` keeps the old key for anything reading it
        return response()->json(['business' => $data['items'], 'data' => $data]);
    }

    private function base(): Builder
    {
        return Listing::query()->where('listings.user_id', $this->userId);
    }

    private function isActive(Listing $l): bool
    {
        return (int) $l->active === 1;
    }

    private function dealOf(Listing $l): string
    {
        return ! $this->isActive($l) ? 'hidden' : ((int) $l->threshold_met === 1 ? 'targetMet' : 'open');
    }

    /** Over every business of the owner, whatever the search or filters. */
    private function stats(): array
    {
        $row = $this->base()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN listings.active = 1 THEN 1 ELSE 0 END) as active')
            ->selectRaw('SUM(CASE WHEN listings.active = 1 AND listings.threshold_met = 1 THEN 1 ELSE 0 END) as target_met')
            ->selectRaw('COALESCE(SUM(listings.investment_needed), 0) as goal_total')
            ->selectRaw('COALESCE(SUM(listings.amount_collected), 0) as raised_total')
            ->selectRaw('COALESCE(SUM(listings.invest_count), 0) as investors')
            ->toBase()->first();

        $total = (int) ($row->total ?? 0);
        $active = (int) ($row->active ?? 0);

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'target_met' => (int) ($row->target_met ?? 0),
            'goal_total' => round((float) ($row->goal_total ?? 0), 2),
            'raised_total' => round((float) ($row->raised_total ?? 0), 2),
            'investors' => (int) ($row->investors ?? 0),
        ];
    }
}
