<?php

namespace App\Service\Shared;

use App\Models\Misc\Event;
use App\Support\ApiContract;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The "My events" table: a page of the person's own events with search, filters, sort and export on the database, the
 * option counts for the filter popover, and the numbers for the three summary cards over ALL of their events.
 *
 * Derived filters (worked out per row, the same way the page shows them):
 *   status  active | inactive        (active = 1)
 *   timing  upcoming | live | ended  (against the start and end time, now)
 *   format  inPerson | virtual       (location_type)
 *   cost    free | paid              (free = cost type "free" or no price)
 * Column filters: type, sector, country, city (options), price (min~max), date (start, past or future), created,
 * tickets (yes|no: has a ticket link). All are described in `filter_schema`.
 */
class EventList
{
    public function __construct(private int $userId)
    {
    }

    /** True when the request asks for the paged / searchable form (the old full list stays for everything else). */
    public static function wanted(Request $request): bool
    {
        return ApiContract::wantsPagination($request) || $request->boolean('export')
            || $request->filled('search') || $request->filled('filters') || $request->filled('sort');
    }

    public function respond(Request $request): JsonResponse
    {
        $list = $this->list($request);
        [$rows, $page] = $list->fetch();

        $data = [
            'items' => $rows->values(),
            'facets' => $this->facets($list),
            'filter_schema' => $list->filterSchema(),
            'stats' => $this->stats(),
        ];
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }
        if ($list->wantsExport()) {
            $data['export_truncated'] = $list->exportTruncated();
        }

        return response()->json(['success' => true, 'message' => 'Events retrieved', 'data' => $data]);
    }

    private function base(): Builder
    {
        return Event::query()->where('user_id', $this->userId);
    }

    /** Quick ranges for the event date: an event can be ahead or behind. */
    private const AROUND = ['today', 'next7Days', 'next30Days', 'yesterday', 'last7Days', 'last30Days', 'thisMonth', 'lastMonth', 'thisYear'];

    private function list(Request $request): ListQuery
    {
        $cases = $this->cases();
        $derived = fn (string $key) => fn (Builder $q, array $v) => $this->either($q, $v, $cases[$key]);
        $counted = fn (string $key) => fn (Builder $q) => $this->countCases($q, $key);
        $tl = 'shared:events.eventsList.';

        return ListQuery::for($this->base(), $request)
            ->search(['event_name', 'sector', 'event_type', 'city', 'country', 'venue'])
            ->filter('id', 'id')
            // Described filters: the My events panel, chips and URL state come from `filter_schema`
            ->options('status', $derived('status'), label: 'app:status', values: ['active', 'inactive'],
                optionLabels: ['active' => 'app:active', 'inactive' => 'app:inactive'], countBy: $counted('status'))
            ->options('timing', $derived('timing'), label: $tl.'filters.when', fallback: 'When', values: ['upcoming', 'live', 'ended'],
                optionLabels: ['upcoming' => 'shared:events.browseEvents.status.comingSoon', 'live' => 'shared:events.browseEvents.status.liveNow', 'ended' => 'shared:events.browseEvents.status.ended'],
                countBy: $counted('timing'))
            ->options('type', 'event_type', label: $tl.'filters.type', fallback: 'Event type')
            ->options('sector', 'sector', label: 'app:sector')
            ->options('format', $derived('format'), label: $tl.'filters.format', fallback: 'Format', values: ['inPerson', 'virtual'],
                optionLabels: ['inPerson' => $tl.'inPerson', 'virtual' => $tl.'virtual'], countBy: $counted('format'))
            ->options('cost', $derived('cost'), label: 'app:cost', values: ['free', 'paid'],
                optionLabels: ['free' => 'app:free', 'paid' => 'app:paid'], countBy: $counted('cost'))
            ->options('country', 'country', label: 'app:country', searchable: true)
            ->options('city', 'city', label: 'app:city', searchable: true)
            ->numberRange('price', 'price', label: 'app:listFilterTicketPrice', fallback: 'Ticket price')
            ->dateRange('date', 'start_date', label: $tl.'filters.date', fallback: 'Event date', presets: self::AROUND)
            ->dateRange('created', 'created_at', label: 'app:created')
            ->boolean('tickets', fn (Builder $q, bool $yes) => $yes
                ? $q->whereNotNull('events.ticket_link')->where('events.ticket_link', '!=', '')
                : $q->where(fn (Builder $w) => $w->whereNull('events.ticket_link')->orWhere('events.ticket_link', '')),
                label: 'app:listFilterHasTicketLink', fallback: 'Has a ticket link')
            ->sortable(['event_name' => 'event_name', 'start' => 'start_date', 'price' => 'price'], default: '-start');
    }

    /** The derived values of each filter, as query conditions (a row is in a value when its condition holds). */
    private function cases(): array
    {
        $now = now()->toDateTimeString();

        return [
            'status' => [
                'active' => fn (Builder $w) => $w->where('active', 1),
                'inactive' => fn (Builder $w) => $w->where(fn (Builder $x) => $x->whereNull('active')->orWhere('active', '!=', 1)),
            ],
            'timing' => [
                'upcoming' => fn (Builder $w) => $w->where('start_date', '>', $now),
                'live' => fn (Builder $w) => $w->where('start_date', '<=', $now)->where('end_date', '>=', $now),
                'ended' => fn (Builder $w) => $w->where('start_date', '<=', $now)->where('end_date', '<', $now),
            ],
            'format' => [
                'virtual' => fn (Builder $w) => $w->where('location_type', 'virtual'),
                'inPerson' => fn (Builder $w) => $w->where(fn (Builder $x) => $x->whereNull('location_type')->orWhere('location_type', '!=', 'virtual')),
            ],
            'cost' => [
                'free' => fn (Builder $w) => $w->where(fn (Builder $x) => $this->whereFree($x)),
                'paid' => fn (Builder $w) => $w->where(fn (Builder $x) => $x->where('cost_type', '!=', 'free')->where('price', '>', 0)),
            ],
        ];
    }

    /** A row matches when it is in ANY of the picked derived values. */
    private function either(Builder $q, array $values, array $conditions): void
    {
        $q->where(function (Builder $any) use ($values, $conditions) {
            foreach ($values as $value) {
                if (isset($conditions[$value])) {
                    $any->orWhere(fn (Builder $w) => ($conditions[$value])($w));
                }
            }
        });
    }

    private function whereFree(Builder $q): void
    {
        $q->where('cost_type', 'free')->orWhereNull('price')->orWhere('price', '<=', 0);
    }

    private const FREE = "(events.cost_type = 'free' OR events.price IS NULL OR events.price <= 0)";

    /** The derived values of each filter as SQL (for counting them in one query). */
    private function caseSql(): array
    {
        $now = now()->toDateTimeString();
        $free = self::FREE;

        return [
            'status' => ['active' => 'events.active = 1', 'inactive' => '(events.active IS NULL OR events.active <> 1)'],
            'timing' => [
                'upcoming' => ['events.start_date > ?', [$now]],
                'live' => ['events.start_date <= ? AND events.end_date >= ?', [$now, $now]],
                'ended' => ['events.start_date <= ? AND events.end_date < ?', [$now, $now]],
            ],
            'format' => ['inPerson' => "(events.location_type IS NULL OR events.location_type <> 'virtual')", 'virtual' => "events.location_type = 'virtual'"],
            'cost' => ['free' => $free, 'paid' => "NOT $free"],
        ];
    }

    /** [{value, count}] of a derived filter over $q (the list with the search and the other filters applied). */
    private function countCases(Builder $q, string $key): array
    {
        $cases = $this->caseSql()[$key];
        foreach ($cases as $value => $case) {
            [$sql, $bindings] = is_array($case) ? $case : [$case, []];
            $q->selectRaw("SUM(CASE WHEN $sql THEN 1 ELSE 0 END) as ".'c_'.$value, $bindings);
        }
        $row = $q->toBase()->first();

        return array_map(fn ($value) => ['value' => $value, 'count' => (int) ($row->{'c_'.$value} ?? 0)], array_keys($cases));
    }

    /** Option counts per filter, each counted with the search and the other filters applied (older screens read these). */
    private function facets(ListQuery $list): array
    {
        $facets = $list->facets(['type', 'sector']);
        foreach (array_keys($this->caseSql()) as $key) {
            $facets[$key] = array_values(array_filter($this->countCases($list->query($key, sorted: false)->reorder(), $key), fn ($o) => $o['count'] > 0));
        }

        return $facets;
    }

    /** Numbers for the summary cards, over every event of the person (they do not change with the search). */
    private function stats(): array
    {
        $now = now()->toDateTimeString();
        $monthStart = now()->startOfMonth()->toDateTimeString();
        $monthEnd = now()->endOfMonth()->toDateTimeString();

        $row = $this->base()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active')
            ->selectRaw("SUM(CASE WHEN location_type = 'virtual' THEN 1 ELSE 0 END) as virtual_count") // VIRTUAL is a reserved word in MySQL
            ->selectRaw('SUM(CASE WHEN start_date > ? THEN 1 ELSE 0 END) as upcoming', [$now])
            ->selectRaw('SUM(CASE WHEN start_date <= ? AND end_date >= ? THEN 1 ELSE 0 END) as live', [$now, $now])
            ->selectRaw('SUM(CASE WHEN start_date >= ? AND start_date <= ? THEN 1 ELSE 0 END) as this_month', [$monthStart, $monthEnd])
            ->toBase()->first();

        $total = (int) ($row->total ?? 0);
        $upcoming = (int) ($row->upcoming ?? 0);
        $live = (int) ($row->live ?? 0);
        $next = $this->base()->where('start_date', '>', $now)->orderBy('start_date')->orderBy('id')->first(['id', 'event_name', 'start_date']);

        return [
            'total' => $total,
            'active' => (int) ($row->active ?? 0),
            'inactive' => $total - (int) ($row->active ?? 0),
            'virtual' => (int) ($row->virtual_count ?? 0),
            'in_person' => $total - (int) ($row->virtual_count ?? 0),
            'upcoming' => $upcoming,
            'live' => $live,
            'ended' => $total - $upcoming - $live,
            'this_month' => (int) ($row->this_month ?? 0),
            'next' => $next ? ['id' => $next->id, 'event_name' => $next->event_name, 'start_date' => $next->start_date] : null,
        ];
    }
}
