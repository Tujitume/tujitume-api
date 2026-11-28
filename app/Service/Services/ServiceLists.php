<?php

namespace App\Service\Services;

use App\Models\Auth\User;
use App\Models\Services\ServiceBooking;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use App\Support\ApiContract;
use App\Support\ListQuery;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The service provider's tables (my services, booking requests, a service's customers, milestones per service) and the
 * customer's My Bookings, answered on the server: a page of rows with search, filters, sort and export, the option
 * counts for the filter popover and the totals for the cards over the WHOLE list (not the page on screen).
 *
 * Every endpoint keeps its old full-list answer when the request has none of the list parameters (see wanted()).
 */
class ServiceLists
{
    /** Milestone statuses that still have work in them (shown as "active"). */
    public const ACTIVE_MILESTONE = ['To Do', 'In Progress'];
    public const DONE_MILESTONE = 'Done';

    /** Booking statuses after the work is finished. */
    private const FINISHED = ['delivered', 'done', 'completed'];
    /** A booking's payment as the filter reads it. */
    private const PAID_KEY = "CASE WHEN service_bookings.paid = 1 THEN 'paid' ELSE 'unpaid' END";
    /** What a booking costs: the agreed price, else the service's price. */
    private const AMOUNT_SQL = 'COALESCE(service_bookings.agreed_price, (SELECT services.price FROM services WHERE services.id = service_bookings.service_id LIMIT 1), 0)';
    private const ACTIVE = ['confirmed', 'paid', 'in_progress'];
    /** How many milestones a service has, and what they add up to (the milestones page's number filters). */
    private const MILESTONE_COUNT_SQL = '(SELECT COUNT(*) FROM smilestones WHERE smilestones.listing_id = services.id)';
    private const MILESTONE_TOTAL_SQL = '(SELECT COALESCE(SUM(smilestones.amount), 0) FROM smilestones WHERE smilestones.listing_id = services.id)';
    /** Quick ranges for a day that may be ahead or behind (a booked service date). */
    private const PAST_AND_FUTURE = ['today', 'next7Days', 'next30Days', 'yesterday', 'last7Days', 'last30Days', 'thisMonth', 'lastMonth', 'thisYear'];
    /** Booking statuses as the filter shows them (the customer's booking status labels). */
    private const BOOKING_STATUS_LABELS = [
        'pending' => 'shared:myBookings.bookingsListUi.statuses.pending', 'confirmed' => 'shared:myBookings.bookingsListUi.statuses.confirmed',
        'paid' => 'shared:myBookings.bookingsListUi.statuses.paid', 'in_progress' => 'shared:myBookings.bookingsListUi.statuses.in_progress',
        'delivered' => 'shared:myBookings.bookingsListUi.statuses.delivered', 'done' => 'shared:myBookings.bookingsListUi.statuses.done',
        'completed' => 'shared:myBookings.bookingsListUi.statuses.completed',
    ];

    /** Only what the tables show of the customer (never tokens or payment ids). */
    private const PERSON = 'id,first_name,last_name,email,website,image,user_type_id';

    public function __construct(private int $userId)
    {
    }

    /** True when the request asks for the paged / searchable form (the old full list stays for everything else). */
    public static function wanted(Request $request): bool
    {
        return ApiContract::wantsPagination($request) || $request->boolean('export')
            || $request->filled('search') || $request->filled('filters') || $request->filled('sort');
    }

    // ── my services (GET /services/dashhome/service) ───────────────────────────────────────────────

    public function services(Request $request): JsonResponse
    {
        $base = Services::query()->where('user_id', $this->userId);
        $list = ListQuery::for($base, $request)
            ->search(['name', 'category', 'location', 'details'])
            ->filter('id', 'id')
            // Described filters: the My Services panel, chips and URL state come from `filter_schema`
            ->options('status', fn (Builder $q, array $values) => $this->whereActive($q, $values), label: 'app:status',
                values: ['active', 'inactive'], optionLabels: ['active' => 'app:active', 'inactive' => 'app:inactive'],
                countBy: fn (Builder $q) => $this->activeCounts($q))
            ->options('category', 'category', label: 'app:listFilterCategory', fallback: 'Category')
            ->options('location', 'location', label: 'app:location', searchable: true)
            ->numberRange('price', 'price', label: 'app:listFilterPrice', fallback: 'Price')
            ->numberRange('rating', 'rating', label: 'app:rating')
            ->boolean('photo', fn (Builder $q, bool $yes) => $this->whereHasPhoto($q, $yes), label: 'app:listFilterHasPhoto', fallback: 'Has a photo')
            ->boolean('bookings', fn (Builder $q, bool $yes) => $this->whereBooked($q, $yes), label: 'app:listFilterHasBookings', fallback: 'Has bookings')
            ->boolean('milestones', fn (Builder $q, bool $yes) => $yes ? $q->whereHas('milestones') : $q->whereDoesntHave('milestones'),
                label: 'app:listFilterHasMilestones', fallback: 'Has milestones')
            ->dateRange('created', 'created_at', label: 'app:created')
            ->sortable(['id' => 'id', 'name' => 'name', 'price' => 'price', 'created' => 'created_at'], default: '-id');
        [$rows, $page] = $list->fetch();
        // the provider's own list: their private business details too (hidden everywhere else)
        $rows->each->makeVisible(Services::OWNER_ONLY);

        // `facets` stays for older screens; the panel reads filter_schema
        $facets = $list->facets(['category', 'location']);
        $facets['status'] = array_values(array_filter($this->activeCounts($list->query('status', sorted: false)->reorder()), fn ($o) => $o['count'] > 0));

        $all = Services::query()->where('user_id', $this->userId)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_count')
            ->toBase()->first();
        $stats = ['total' => (int) ($all->total ?? 0), 'active' => (int) ($all->active_count ?? 0)];
        $stats['inactive'] = $stats['total'] - $stats['active'];

        return $this->respond('Services retrieved', $rows, $page, $list, $facets, $stats, ['services'], $list->filterSchema());
    }

    // ── booking requests and a service's customers (GET /services/service_booking) ─────────────────

    /** ?service_id=: every booking of that service; without it, the inbox of pending requests (as the old list). */
    public function providerBookings(Request $request, ?int $serviceId): JsonResponse
    {
        // view (the provider's booking page tabs): pending requests (default), active work, or every booking incl. ended
        $view = in_array($request->input('view'), self::PROVIDER_VIEWS, true) ? $request->input('view') : null;
        $base = $this->providerBase($serviceId, $view)->with(['service', 'booker:'.self::PERSON]);
        $onService = fn (string $column) => fn (Builder $q, array $values) => $q->whereHas('service', fn (Builder $s) => $s->whereIn($column, $values));
        $list = ListQuery::for($base, $request)
            ->search(['note', 'status', 'stage', 'service.name', 'service.location', 'booker.first_name', 'booker.last_name', 'booker.email'])
            ->filter('id', 'id')
            // Described filters: the booking requests and a service's customers build their panel from `filter_schema`
            ->options('status', 'status', label: 'app:status', optionLabels: self::BOOKING_STATUS_LABELS)
            ->options('stage', 'stage', label: 'app:stage')
            ->options('payment', DB::raw(self::PAID_KEY), label: 'app:payment', values: ['paid', 'unpaid'],
                optionLabels: ['paid' => 'shared:myBookings.table.paid', 'unpaid' => 'shared:myBookings.table.unpaid']);
        if ($serviceId) {
            // one service's customers: the service, its category and its place are that service's (nothing to pick)
            $list->filter('service', $onService('name'))->filter('category', $onService('category'))->filter('location', $onService('location'));
        } else {
            $list
                // a service id (the panel), or a service name (older links)
                ->options('service', fn (Builder $q, array $values) => $this->whereService($q, $values), label: 'app:service',
                    optionLabels: fn (array $ids) => Services::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
                    countBy: 'service_id', searchable: true)
                ->options('category', $onService('category'), label: 'app:listFilterCategory', fallback: 'Category',
                    countBy: fn (Builder $q) => $this->serviceCounts($q, 'category'))
                ->options('location', $onService('location'), label: 'app:location', searchable: true,
                    countBy: fn (Builder $q) => $this->serviceCounts($q, 'location'));
        }
        $list
            ->entity('customer', 'booker_id', fn (array $ids) => User::query()->whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'email'])
                ->mapWithKeys(fn (User $u) => [$u->id => trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: $u->email])->all(),
                label: 'app:listFilterCustomer', fallback: 'Customer')
            ->dateRange('date', fn (Builder $q, ?string $from, ?string $to) => ($this->dayRange('date'))($q, [($from ?? '').'~'.($to ?? '')]),
                label: 'app:listFilterServiceDate', fallback: 'Service date', presets: self::PAST_AND_FUTURE)
            ->dateRange('created', 'created_at', label: 'app:listFilterBookedOn', fallback: 'Booked on')
            ->numberRange('amount', DB::raw(self::AMOUNT_SQL), label: 'app:amount')
            ->boolean('upcoming', fn (Builder $q, bool $yes) => $this->whereUpcoming($q, $yes), label: 'app:upcoming')
            ->boolean('offer', 'is_offer_booking', label: 'app:listFilterFromOffer', fallback: 'Booked from an offer')
            ->sortable(['id' => 'id', 'date' => 'date', 'status' => 'status', 'stage' => 'stage', 'created' => 'created_at',
                'customer' => $this->byCustomer(), 'service' => $this->byService('name'), 'amount' => $this->byAmount()], default: '-created');
        [$rows, $page] = $list->fetch();

        $facets = $list->facets(['status', 'stage']);
        foreach (['service' => 'name', 'category' => 'category', 'location' => 'location'] as $key => $column) {
            $facets[$key] = $this->serviceFacet($list, $key, $column);
        }

        // Counted over every booking of theirs (one service's, or all), so each tab can show its number
        $counts = $this->providerBase($serviceId, 'all')->reorder()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) as pending_count")
            ->selectRaw("SUM(CASE WHEN LOWER(status) IN ('confirmed', 'paid', 'in_progress') THEN 1 ELSE 0 END) as active_count")
            ->selectRaw("SUM(CASE WHEN LOWER(status) IN ('delivered', 'done', 'completed') THEN 1 ELSE 0 END) as finished_count")
            ->selectRaw("SUM(CASE WHEN LOWER(status) IN ('rejected', 'cancelled') THEN 1 ELSE 0 END) as ended_count")
            ->selectRaw('COUNT(DISTINCT booker_id) as customers')
            ->toBase()->first();
        // total: the bookings of the list shown (as before); all / ended: what the other tabs hold
        $shown = $view ?? ($serviceId ? 'all' : 'pending');
        $stats = [
            'total' => (int) ($shown === 'pending' ? $counts->pending_count : ($shown === 'active' ? $counts->active_count : $counts->total) ?? 0),
            'all' => (int) ($counts->total ?? 0),
            'customers' => (int) ($counts->customers ?? 0),
            'pending' => (int) ($counts->pending_count ?? 0),
            'active' => (int) ($counts->active_count ?? 0),
            'finished' => (int) ($counts->finished_count ?? 0),
            'ended' => (int) ($counts->ended_count ?? 0),
        ];

        $rows = $rows->map(fn (ServiceBooking $b) => $this->providerRow($b));

        return $this->respond('Bookings retrieved', $rows, $page, $list, $facets, $stats, ['results'], $list->filterSchema());
    }

    /** The provider's bookings that have a service and a customer: one service's, or the pending requests. */
    public const PROVIDER_VIEWS = ['pending', 'active', 'all'];

    /**
     * The provider's bookings that have a service and a customer. One service's (every booking of it), or by view:
     * 'pending' requests (the default inbox), 'active' work (accepted, paid, in progress) or 'all' (rejected,
     * cancelled and finished included).
     */
    public function providerBase(?int $serviceId, ?string $view = null): Builder
    {
        $view ??= $serviceId ? 'all' : 'pending';

        return ServiceBooking::query()
            ->where('service_owner_id', $this->userId)
            ->when($serviceId, fn (Builder $q) => $q->where('service_id', $serviceId))
            ->when($view === 'pending', fn (Builder $q) => $q->whereRaw('LOWER(status) = ?', ['pending']))
            ->when($view === 'active', fn (Builder $q) => $q->whereIn(DB::raw('LOWER(status)'), ['confirmed', 'paid', 'in_progress']))
            ->whereHas('service')
            ->whereHas('booker');
    }

    /** One booking as the provider's tables read it (the same fields the old list sent). */
    public function providerRow(ServiceBooking $book): ServiceBooking
    {
        $service = $book->service;
        $customer = $book->booker;
        $book->unsetRelation('service'); // `service` is the service's NAME in these rows, not the related record

        $book->location = $service?->location;
        $book->service = $service?->name;
        $book->service_image = $service?->image;
        $book->category = $service?->category;
        $book->customer_name = trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''));
        $book->customer_id = $book->booker_id;
        $book->website = $customer?->website;
        $book->email = $customer?->email;
        $book->deadline = $this->deadline($book->date);
        // The exact moment behind `deadline` (to answer a request, or to be paid by), for a live countdown
        $book->deadline_at = in_array($book->status, ['rejected', 'cancelled'], true) ? null : $this->deadlineAt($book->date);

        return $book;
    }

    // ── the customer's bookings (GET /services/my_booking) ─────────────────────────────────────────

    public function myBookings(Request $request): JsonResponse
    {
        $base = $this->customerBase()->with(['service.owner:'.self::PERSON]);
        $list = ListQuery::for($base, $request)
            ->search(['note', 'status', 'stage', 'service.name', 'service.category', 'service.location'])
            ->filter('id', 'id')
            // Described filters: the My Bookings panel, chips and URL state come from `filter_schema`
            ->options('status', 'status', label: 'app:status')
            ->options('payment', DB::raw(self::PAID_KEY), label: 'shared:myBookings.table.payment', values: ['paid', 'unpaid'],
                optionLabels: ['paid' => 'shared:myBookings.table.paid', 'unpaid' => 'shared:myBookings.table.unpaid'], fallback: 'Payment')
            ->options('category', fn (Builder $q, array $values) => $q->whereHas('service', fn (Builder $s) => $s->whereIn('category', $values)),
                label: 'shared:myBookings.table.category', countBy: fn (Builder $q) => $this->serviceCounts($q, 'category'), fallback: 'Category')
            ->dateRange('date', fn (Builder $q, ?string $from, ?string $to) => ($this->dayRange('date'))($q, [($from ?? '').'~'.($to ?? '')]),
                label: 'shared:myBookings.bookingsListUi.serviceDate', fallback: 'Service date',
                presets: ['today', 'next7Days', 'next30Days', 'yesterday', 'last7Days', 'last30Days', 'thisMonth', 'lastMonth', 'thisYear'])
            ->numberRange('amount', DB::raw(self::AMOUNT_SQL), label: 'app:amount')
            ->boolean('upcoming', fn (Builder $q, bool $yes) => $yes
                ? $q->where('service_bookings.date', '>=', now()->toDateString())
                : $q->where(fn (Builder $past) => $past->whereNull('service_bookings.date')->orWhere('service_bookings.date', '<', now()->toDateString())),
                label: 'app:upcoming')
            ->sortable(['id' => 'id', 'date' => 'date', 'status' => 'status', 'created' => 'created_at',
                'service' => $this->byService('name'), 'amount' => $this->byAmount()], default: '-date');
        [$rows, $page] = $list->fetch();

        // `facets` stays for older screens (the payment options only when some booking has them); the panel reads filter_schema
        $facets = $list->facets(['status', 'category', 'payment']);
        $facets['payment'] = array_values(array_filter($facets['payment'] ?? [], fn ($o) => $o['count'] > 0));

        $rows = $rows->map(fn (ServiceBooking $b) => $this->customerRow($b));

        return $this->respond('Bookings retrieved', $rows, $page, $list, $facets, $this->myBookingStats(), ['results'], $list->filterSchema());
    }

    public function customerBase(): Builder
    {
        return ServiceBooking::query()->where('booker_id', $this->userId)->whereHas('service');
    }

    /** One booking as the customer's list reads it (the same fields the old list sent, plus the provider's name). */
    public function customerRow(ServiceBooking $book): ServiceBooking
    {
        $service = $book->service;
        $owner = $service?->relationLoaded('owner') ? $service->owner : null;
        $book->unsetRelation('service');

        $book->location = $service?->location;
        $book->service = $service?->name;
        $book->service_image = $service?->image;
        $book->category = $service?->category;
        $book->service_fee = $service?->price;
        $book->provider_name = $owner ? trim($owner->first_name.' '.$owner->last_name) : null;
        $book->deadline = $this->deadline($book->date);
        $book->deadline_at = ServiceBookingFlow::payBy($book);

        return $book;
    }

    /** The cards of My Bookings, over every booking of the person (amount = the agreed price, else the service price). */
    private function myBookingStats(): array
    {
        $today = now()->toDateString();
        $week = now()->addDays(6)->toDateString();
        // Rejected and cancelled bookings are not owed: they count in the total only
        $amount = "CASE WHEN LOWER(service_bookings.status) IN ('rejected', 'cancelled') THEN 0 ELSE COALESCE(service_bookings.agreed_price, s.price, 0) END";
        $finished = "LOWER(service_bookings.status) IN ('delivered', 'done', 'completed')";

        $row = ServiceBooking::query()->where('service_bookings.booker_id', $this->userId)
            ->join('services as s', 's.id', '=', 'service_bookings.service_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN LOWER(service_bookings.status) = 'pending' THEN 1 ELSE 0 END) as pending_count")
            ->selectRaw("SUM(CASE WHEN LOWER(service_bookings.status) IN ('confirmed', 'paid', 'in_progress') THEN 1 ELSE 0 END) as active_count")
            ->selectRaw("SUM(CASE WHEN $finished THEN 1 ELSE 0 END) as finished_count")
            ->selectRaw("SUM(CASE WHEN NOT $finished AND service_bookings.date >= ? THEN 1 ELSE 0 END) as upcoming_count", [$today])
            ->selectRaw("SUM(CASE WHEN NOT $finished AND service_bookings.date >= ? AND service_bookings.date <= ? THEN 1 ELSE 0 END) as week_count", [$today, $week])
            ->selectRaw("MIN(CASE WHEN NOT $finished AND service_bookings.date >= ? THEN service_bookings.date END) as next_date", [$today])
            ->selectRaw("COALESCE(SUM($amount), 0) as amount_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN service_bookings.paid = 1 THEN $amount ELSE 0 END), 0) as paid_total")
            ->selectRaw("SUM(CASE WHEN service_bookings.paid = 1 OR LOWER(service_bookings.status) IN ('rejected', 'cancelled') THEN 0 ELSE 1 END) as unpaid_count")
            ->toBase()->first();

        $total = (float) ($row->amount_total ?? 0);
        $paid = (float) ($row->paid_total ?? 0);

        return [
            'total' => (int) ($row->total ?? 0),
            'pending' => (int) ($row->pending_count ?? 0),
            'active' => (int) ($row->active_count ?? 0),
            'finished' => (int) ($row->finished_count ?? 0),
            'upcoming' => (int) ($row->upcoming_count ?? 0),
            'this_week' => (int) ($row->week_count ?? 0),
            'next_date' => $row->next_date ?? null,
            'amount_total' => round($total, 2),
            'paid_total' => round($paid, 2),
            'unpaid_total' => round($total - $paid, 2),
            'unpaid_count' => (int) ($row->unpaid_count ?? 0),
        ];
    }

    // ── milestones per service (GET /services/bBQhdsfE_WWe4Q-_f7ieh7Hdhf4F_-all) ───────────────────

    public function milestoneServices(Request $request): JsonResponse
    {
        $base = Services::query()->where('user_id', $this->userId)
            ->withCount([
                'milestones as milestoneCount',
                'milestones as active' => fn (Builder $q) => $q->whereIn('status', self::ACTIVE_MILESTONE),
                'milestones as completed' => fn (Builder $q) => $q->where('status', self::DONE_MILESTONE),
            ])
            ->withSum('milestones as totalAmount', 'amount');
        $list = ListQuery::for($base, $request)
            ->search(['name', 'category', 'location'])
            ->filter('id', 'id')
            // Described filters: the milestones page builds its panel, chips and URL state from `filter_schema`
            ->options('progress', fn (Builder $q, array $values) => $q->where(function (Builder $any) use ($values) {
                foreach ($values as $value) {
                    $any->orWhere(fn (Builder $one) => $this->whereProgress($one, (string) $value));
                }
            }), label: 'app:progress', values: ['open', 'done', 'none'],
                optionLabels: ['open' => 'app:active', 'done' => 'app:completed', 'none' => 'app:noMilestonesYet'],
                countBy: fn (Builder $q) => $this->progressCounts($q))
            ->options('category', 'category', label: 'app:listFilterCategory', fallback: 'Category')
            ->options('location', 'location', label: 'app:location', searchable: true)
            ->numberRange('count', DB::raw(self::MILESTONE_COUNT_SQL), label: 'app:listFilterMilestoneCount', fallback: 'Number of milestones')
            ->numberRange('total', DB::raw(self::MILESTONE_TOTAL_SQL), label: 'app:listFilterMilestoneTotal', fallback: 'Milestones value')
            ->numberRange('price', 'price', label: 'app:listFilterPrice', fallback: 'Price')
            ->boolean('active', fn (Builder $q, bool $yes) => $yes ? $q->where('active', 1) : $q->where(fn (Builder $i) => $i->whereNull('active')->orWhere('active', '!=', 1)),
                label: 'app:active')
            ->dateRange('created', 'created_at', label: 'app:created')
            ->sortable(['id' => 'id', 'name' => 'name', 'price' => 'price'], default: '-id');
        [$rows, $page] = $list->fetch();

        $rows->each(function (Services $s) {
            $s->totalAmount = (float) ($s->totalAmount ?? 0);
        });

        $facets = $list->facets(['category', 'location']);
        $facets['progress'] = array_values(array_filter($this->progressCounts($list->query('progress', sorted: false)->reorder()), fn ($o) => $o['count'] > 0));

        $m = Smilestones::query()->whereIn('listing_id', Services::query()->where('user_id', $this->userId)->select('id'))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status IN ('To Do', 'In Progress') THEN 1 ELSE 0 END) as active_count")
            ->selectRaw("SUM(CASE WHEN status = 'Done' THEN 1 ELSE 0 END) as completed_count")
            ->selectRaw('COALESCE(SUM(amount), 0) as amount_total')
            ->toBase()->first();
        $stats = [
            'services' => Services::query()->where('user_id', $this->userId)->count(),
            'milestones' => (int) ($m->total ?? 0),
            'active' => (int) ($m->active_count ?? 0),
            'completed' => (int) ($m->completed_count ?? 0),
            'total_amount' => round((float) ($m->amount_total ?? 0), 2),
        ];

        return $this->respond('Services retrieved', $rows, $page, $list, $facets, $stats, ['business'], $list->filterSchema());
    }

    /** [{value, count}] of open / done / none over a services query (the progress filter's counts). */
    private function progressCounts(Builder $q): array
    {
        return array_map(fn ($p) => ['value' => $p, 'count' => $this->whereProgress(clone $q, $p)->count()], ['open', 'done', 'none']);
    }

    /** open = has a milestone not done yet; done = has milestones and all are done; none = no milestones. */
    private function whereProgress(Builder $q, string $progress): Builder
    {
        $notDone = fn (Builder $m) => $m->where(fn (Builder $s) => $s->whereNull('status')->orWhere('status', '!=', self::DONE_MILESTONE));

        return match ($progress) {
            'none' => $q->whereDoesntHave('milestones'),
            'done' => $q->whereHas('milestones')->whereDoesntHave('milestones', $notDone),
            'open' => $q->whereHas('milestones', $notDone),
            default => $q->whereRaw('1 = 0'),
        };
    }

    // ── shared ─────────────────────────────────────────────────────────────────────────────────────

    private function respond(string $message, Collection $rows, $page, ListQuery $list, array $facets, array $stats, array $legacy, ?array $schema = null): JsonResponse
    {
        $items = $rows->values();
        $data = ['items' => $items, 'facets' => $facets, 'stats' => $stats];
        if ($schema !== null) {
            $data['filter_schema'] = $schema;
        }
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }
        if ($list->wantsExport()) {
            $data['export_truncated'] = $list->exportTruncated();
        }

        $body = ['success' => true, 'message' => $message, 'data' => $data];
        foreach ($legacy as $key) {
            $body[$key] = $items;
        }

        return response()->json($body);
    }

    /** Sort bookings by the customer's name (first, then last name), from the users table. */
    private function byCustomer(): \Closure
    {
        return function (Builder $q, string $direction) {
            foreach (['first_name', 'last_name'] as $column) {
                $q->orderBy(User::query()->selectRaw("LOWER(COALESCE($column, ''))")->whereColumn('users.id', 'service_bookings.booker_id')->limit(1), $direction);
            }
        };
    }

    /** Sort bookings by a column of the booked service (its name). */
    private function byService(string $column): \Closure
    {
        return fn (Builder $q, string $direction) => $q->orderBy(
            Services::query()->selectRaw("LOWER(COALESCE($column, ''))")->whereColumn('services.id', 'service_bookings.service_id')->limit(1),
            $direction
        );
    }

    /** Sort bookings by the amount the tables show: the agreed price, else the service's price. */
    private function byAmount(): \Closure
    {
        return fn (Builder $q, string $direction) => $q->orderByRaw(
            'COALESCE(service_bookings.agreed_price, (SELECT services.price FROM services WHERE services.id = service_bookings.service_id LIMIT 1), 0) '.($direction === 'desc' ? 'desc' : 'asc')
        );
    }

    /** [{value, count}] of active / inactive over a services query. */
    private function activeCounts(Builder $q): array
    {
        $row = $q->toBase()->select([])->selectRaw('SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_count, COUNT(*) as total')->first();
        $active = (int) ($row->active_count ?? 0);

        return [['value' => 'active', 'count' => $active], ['value' => 'inactive', 'count' => (int) ($row->total ?? 0) - $active]];
    }

    private function whereHasPhoto(Builder $q, bool $yes): void
    {
        $yes ? $q->whereNotNull('services.image')->where('services.image', '!=', '')
            : $q->where(fn (Builder $none) => $none->whereNull('services.image')->orWhere('services.image', ''));
    }

    /** Services that someone has booked (any status), or none has. */
    private function whereBooked(Builder $q, bool $yes): void
    {
        $booked = fn ($b) => $b->selectRaw('1')->from('service_bookings')->whereColumn('service_bookings.service_id', 'services.id');
        $yes ? $q->whereExists($booked) : $q->whereNotExists($booked);
    }

    /** A booking's service by id (the panel), or by name (older links). */
    private function whereService(Builder $q, array $values): void
    {
        $ids = array_values(array_filter($values, fn ($v) => ctype_digit((string) $v)));
        $q->where(fn (Builder $any) => $any->whereIn('service_bookings.service_id', $ids ?: [0])
            ->orWhereHas('service', fn (Builder $s) => $s->whereIn('name', $values)));
    }

    /** The booked day is today or later (the day is kept as text, "2026-11-25"). */
    private function whereUpcoming(Builder $q, bool $yes): void
    {
        $today = now()->toDateString();
        $yes ? $q->whereRaw('SUBSTR(service_bookings.date, 1, 10) >= ?', [$today])
            : $q->where(fn (Builder $past) => $past->whereNull('service_bookings.date')->orWhereRaw('SUBSTR(service_bookings.date, 1, 10) < ?', [$today]));
    }

    private function whereActive(Builder $q, array $values): void
    {
        $q->where(function (Builder $any) use ($values) {
            if (in_array('active', $values, true)) {
                $any->orWhere('active', 1);
            }
            if (in_array('inactive', $values, true)) {
                $any->orWhere(fn (Builder $i) => $i->whereNull('active')->orWhere('active', '!=', 1));
            }
        });
    }

    /** filters[date]=from~to over a column that holds the day as text ("2026-11-25"), compared as days. */
    private function dayRange(string $column): \Closure
    {
        return function (Builder $q, array $values) use ($column) {
            [$from, $to] = array_pad(explode('~', (string) reset($values), 2), 2, '');
            $qualified = $q->getModel()->qualifyColumn($column);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                $q->where($qualified, '>=', $from);
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $q->whereRaw('SUBSTR('.$q->getQuery()->getGrammar()->wrap($qualified).', 1, 10) <= ?', [$to]);
            }
        };
    }

    /** Option counts for a filter on the booked service's column (name, category, location). */
    private function serviceFacet(ListQuery $list, string $key, string $column): array
    {
        return $this->serviceCounts($list->query($key, sorted: false)->reorder(), $column);
    }

    /** [{value, count}] of the booked service's $column over a bookings query. */
    private function serviceCounts(Builder $q, string $column): array
    {
        return $q
            ->join('services as fs', 'fs.id', '=', 'service_bookings.service_id')
            ->whereNotNull("fs.$column")->where("fs.$column", '!=', '')
            ->selectRaw("fs.$column as value, COUNT(*) as count")
            ->groupBy("fs.$column")->orderByDesc('count')->limit(100)
            ->toBase()->get()
            ->map(fn ($r) => ['value' => $r->value, 'count' => (int) $r->count])->all();
    }

    /** "3 days 4 hours" until the booked day (the response deadline the tables show). */
    private function deadlineAt(?string $date): ?string
    {
        try {
            return $date ? Carbon::parse($date)->startOfDay()->toIso8601String() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function deadline(?string $date): ?string
    {
        if (! $date) {
            return null;
        }
        try {
            $start = Carbon::parse($date)->startOfDay();
            // passed: no "Pay within …" (it read "Pay within 3 hours after")
            if (now()->greaterThanOrEqualTo($start)) {
                return null;
            }

            return $start->diffForHumans(now(), ['parts' => 2, 'short' => false, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]);
        } catch (\Throwable) {
            return null;
        }
    }
}
