<?php

namespace App\Service\Business;

use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\Listing;
use App\Models\Milestones\Milestones;
use App\Support\ListQuery;
use App\Support\ListShapes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Investment Bids tabs of a business owner as server lists: pending offers (business_bids), and accepted ones that
 * are active or still under verification (accepted_bids). Rows keep the shape the old full lists had (investor name,
 * business, milestone, photos ...) plus the investor's picture; stats carry the three tab counts.
 * Filters (all in `filter_schema`): type (cash|equipment), business (id, or name for older links), investor, milestone,
 * status + paidInFull (accepted tabs only), amount / equity (min~max), targetMet (yes|no), received (from~to); id for deep links.
 */
class BidList
{
    public const STAGES = ['pending', 'active', 'under-verification'];
    private const ACTIVE = ['Confirmed', 'verified'];
    private const VERIFYING = ['under_verification', 'manager_assigned', 'equipment_released'];

    private ?array $businessIds = null;

    public function __construct(private int $ownerId, private string $stage)
    {
        if (! in_array($stage, self::STAGES, true)) {
            $this->stage = 'pending';
        }
    }

    public function respond(Request $request): JsonResponse
    {
        $base = $this->base($this->stage);
        $table = $base->getModel()->getTable();
        ListShapes::extraSort($base, $request, [
            'investor' => fn (Builder $q, string $dir) => $q->orderBy(User::select('first_name')->whereColumn('users.id', "$table.investor_id")->limit(1), $dir),
            'business' => fn (Builder $q, string $dir) => $q->orderBy(Listing::select('name')->whereColumn('listings.id', "$table.business_id")->limit(1), $dir),
        ]);

        $names = $this->businessNames();
        $milestones = $this->activeMilestones();

        $milestoneOf = fn ($b) => $milestones->get($b->business_id)?->title ?? '';
        $list = ListQuery::for($base, $request)
            ->search(['investor.first_name', 'investor.last_name', 'investor.email', 'listing.name'])
            ->filter('id', 'id')
            // Described filters: each tab's panel, chips and URL state come from `filter_schema`
            ->options('type', DB::raw("CASE WHEN $table.type = 'Asset' THEN 'equipment' ELSE 'cash' END"), label: 'entrepreneur:investmentBids.typeFilter',
                values: ['cash', 'equipment'], optionLabels: ['cash' => 'entrepreneur:investmentBids.cash', 'equipment' => 'entrepreneur:investmentBids.equipmentType'], fallback: 'Type')
            // ids from the panel; a business name (older links) still works
            ->options('business', ListShapes::idOrName('business_id', 'listing', 'name'), label: 'entrepreneur:investmentBids.businessFilter',
                optionLabels: fn (array $ids) => $names->only($ids)->all(), countBy: 'business_id', fallback: 'Business')
            ->entity('investor', 'investor_id', fn (array $ids) => self::personNames($ids), label: 'app:investor')
            ->options('milestone', ListShapes::derivedFilter($this->base($this->stage), ['business_id'], $milestoneOf), label: 'app:milestone',
                countBy: ListShapes::derivedCount(['business_id'], $milestoneOf));
        if ($this->stage !== 'pending') {
            $list->options('status', 'status', label: 'app:status', values: $this->stage === 'active' ? self::ACTIVE : self::VERIFYING)
                ->boolean('paidInFull', 'paid_in_full', label: 'app:entFilterPaidInFull', fallback: 'Paid in full');
        }
        $list->numberRange('amount', 'amount', label: 'app:entFilterOfferAmount', fallback: 'Offer amount')
            ->numberRange('equity', 'representation', label: 'app:equityOffered')
            ->boolean('targetMet', fn (Builder $q, bool $yes) => $yes
                ? $q->whereHas('listing', fn (Builder $l) => $l->where('threshold_met', 1))
                : $q->whereDoesntHave('listing', fn (Builder $l) => $l->where('threshold_met', 1)),
                label: 'app:entFilterBusinessTargetMet', fallback: 'Business target met')
            ->dateRange('received', 'created_at', label: 'entrepreneur:investmentBids.received', fallback: 'Received')
            ->sortable(['received' => 'created_at', 'amount' => 'amount'], default: '-received');

        [$rows, $page] = $list->fetch();
        $items = $rows->map(fn (Model $bid) => $this->shape($bid, $milestones))->values();

        // `facets` keeps the old values (business and milestone by name) for older screens; the panel reads filter_schema
        $facets = [
            'type' => ListShapes::derivedFacet($list->query('type', sorted: false), ['type'], fn ($b) => $b->type === 'Asset' ? 'equipment' : 'cash'),
            'business' => ListShapes::derivedFacet($list->query('business', sorted: false), ['business_id'], fn ($b) => $names->get($b->business_id)),
            'milestone' => ListShapes::derivedFacet($list->query('milestone', sorted: false), ['business_id'], fn ($b) => $milestones->get($b->business_id)?->title),
        ];

        if ($this->stage === 'pending') {
            BusinessBids::where('owner_id', $this->ownerId)->update(['new' => 0]); // as the old list did: the owner has seen them
        }

        $data = ListShapes::envelope($items, $page, $facets, $this->stats(), $list->wantsExport()) + ['filter_schema' => $list->filterSchema()];

        return response()->json(['bids' => $data['items'], 'data' => $data]);
    }

    /** The bids of one tab; a bid whose investor or business is gone is left out, as before. */
    private function base(string $stage): Builder
    {
        $query = $stage === 'pending'
            ? BusinessBids::query()
            : AcceptedBids::query()->whereIn('accepted_bids.status', $stage === 'active' ? self::ACTIVE : self::VERIFYING);

        $table = $query->getModel()->getTable();

        return $query->with(['investor', 'listing'])
            ->where("$table.owner_id", $this->ownerId)
            ->whereHas('investor')
            ->whereHas('listing');
    }

    /** The row the table shows, like the old business_bids / confirmed_bids rows. */
    private function shape(Model $bid, Collection $milestones): array
    {
        $inv = $bid->investor;
        $row = array_merge($bid->toArray(), [
            'investor' => trim($inv->first_name.' '.$inv->last_name),
            'investor_name' => trim(preg_replace('/\s+/', ' ', $inv->first_name.' '.$inv->mname.' '.$inv->last_name)),
            'investor_image' => $inv->image,
            'business' => $bid->listing->name,
            'business_image' => $bid->listing->image,
            'interested_cats' => $inv->interested_cats,
            'past_investment' => $inv->past_investment,
            'website' => $inv->website,
            'email' => $inv->email,
            'milestone' => $milestones->get($bid->business_id)?->title ?? '',
            'photos' => explode(',', (string) $bid->photos),
        ]);
        if ($this->stage === 'pending') {
            $row['threshold'] = $bid->listing->threshold_met;
            $row['inv_range'] = $inv->inv_range;
            $row['status'] = 'Pending';
        }

        return $row;
    }

    /** "First Last" of each investor id. */
    private static function personNames(array $ids): array
    {
        return User::whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (User $u) => [$u->id => trim($u->first_name.' '.$u->last_name)])->all();
    }

    /** Names of the businesses with bids in this tab, by id (for the business filter's counts). */
    private function businessNames(): Collection
    {
        return Listing::whereIn('id', $this->businessIds())->pluck('name', 'id');
    }

    /** The active milestone of each business with bids in this tab, in one query (Listing::active_milestone per row was N+1). */
    private function activeMilestones(): Collection
    {
        return Milestones::whereIn('listing_id', $this->businessIds())->where('active', true)->orderBy('id')
            ->get(['id', 'listing_id', 'title'])
            ->unique('listing_id')
            ->keyBy('listing_id');
    }

    /** The businesses this tab's bids are for. */
    private function businessIds(): array
    {
        return $this->businessIds ??= $this->base($this->stage)->setEagerLoads([])->distinct()->pluck('business_id')->filter()->all();
    }

    /** How many bids each tab holds (the tab counts), whatever the search or filters. */
    private function stats(): array
    {
        return [
            'pending' => $this->base('pending')->count(),
            'active' => $this->base('active')->count(),
            'under_verification' => $this->base('under-verification')->count(),
        ];
    }
}
