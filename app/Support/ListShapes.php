<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Small pieces the entrepreneur-side list endpoints share on top of ListQuery / CollectionList:
 * when a request is in list mode, the response envelope, values worked out in PHP (a place from a free-text location,
 * an active milestone's title) used as filters and counts, and sorts on a related table's column.
 */
final class ListShapes
{
    /** Quick ranges for a date that may lie ahead (a deadline, a due date, an offer's end): the coming days first. */
    public const DUE_PRESETS = ['today', 'next7Days', 'next30Days', 'yesterday', 'last7Days', 'last30Days', 'thisMonth', 'lastMonth', 'thisYear'];

    /** Text that stands in for "nothing given" (the frontend's utils/placeholders cleanValue). */
    private const PLACEHOLDERS = ['not specified', 'not provided', 'not available', 'unspecified', 'n/a', 'na', 'none', 'null', 'undefined', '-', '—'];

    /** True when the request asks for the paged / searchable form; without any of these the endpoint answers as before. */
    public static function wanted(Request $request): bool
    {
        return ApiContract::wantsPagination($request) || $request->boolean('export')
            || $request->filled('search') || $request->filled('filters') || $request->filled('sort');
    }

    public static function clean(mixed $value): string
    {
        $text = trim((string) $value);

        return in_array(mb_strtolower($text), self::PLACEHOLDERS, true) ? '' : $text;
    }

    /** "Westlands, Nairobi, Kenya" → "Nairobi, Kenya": the city + country people filter by (listData placeOf). */
    public static function place(mixed $location): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', self::clean($location))), fn ($p) => $p !== ''));

        return implode(', ', array_slice($parts, -2));
    }

    /**
     * A ListQuery filter for a value worked out in PHP: the rows of $base whose $valueOf(row) is one of the picked values.
     * Only for lists bounded by one person's records (their businesses, bids, applications).
     */
    public static function derivedFilter(Builder $base, array $columns, callable $valueOf): Closure
    {
        return function (Builder $q, array $values) use ($base, $columns, $valueOf) {
            $values = array_map('strval', $values);
            $key = $q->getModel()->getKeyName();
            $ids = (clone $base)->setEagerLoads([])->reorder()->get(array_unique([$key, ...$columns]))
                ->filter(fn ($row) => in_array((string) $valueOf($row), $values, true))
                ->pluck($key);
            $q->whereIn($q->getModel()->qualifyColumn($key), $ids->all());
        };
    }

    /**
     * A ListQuery filter on a related id that also takes the related row's name, as older links and pages sent it
     * (a business, a program): numeric values are ids of $column, anything else is matched on $relation's $title.
     */
    public static function idOrName(string $column, string $relation, string $title): Closure
    {
        return function (Builder $q, array $values) use ($column, $relation, $title) {
            $ids = array_values(array_filter($values, fn ($v) => preg_match('/^\d+$/', (string) $v)));
            $names = array_values(array_diff($values, $ids));
            $q->where(function (Builder $any) use ($q, $ids, $names, $column, $relation, $title) {
                if ($ids) {
                    $any->orWhereIn($q->getModel()->qualifyColumn($column), $ids);
                }
                if ($names) {
                    $any->orWhereHas($relation, fn (Builder $r) => $r->whereIn($title, $names));
                }
            });
        };
    }

    /** A ListQuery countBy for a value worked out in PHP: fn (Builder $q) => [{value, count}] (see derivedFacet). */
    public static function derivedCount(array $columns, callable $valueOf): Closure
    {
        return fn (Builder $q) => self::derivedFacet($q, $columns, $valueOf);
    }

    /** Option counts [{value, count}] for a value worked out in PHP, over a ListQuery query (search + the other filters). */
    public static function derivedFacet(Builder $query, array $columns, callable $valueOf): array
    {
        $key = $query->getModel()->getKeyName();

        return self::counts($query->setEagerLoads([])->reorder()->get(array_unique([$key, ...$columns]))->map($valueOf));
    }

    /** [{value, count}] from a list of values, most common first; empty values are left out. */
    public static function counts(iterable $values): array
    {
        return collect($values)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->countBy(fn ($v) => (string) $v)
            ->map(fn ($count, $value) => ['value' => (string) $value, 'count' => $count])
            ->sortByDesc('count')
            ->values()
            ->take(100)
            ->all();
    }

    /**
     * Sorts ListQuery cannot express (a related table's column, a ratio): key => fn (Builder $q, 'asc'|'desc').
     * Applied to the base query before ListQuery; the list's default sort then only breaks ties.
     */
    public static function extraSort(Builder $base, Request $request, array $sorts): void
    {
        $sort = (string) $request->query('sort', '');
        $key = ltrim($sort, '-');
        if ($key !== '' && isset($sorts[$key])) {
            $sorts[$key]($base, str_starts_with($sort, '-') ? 'desc' : 'asc');
        }
    }

    /** The list part of a response: {items, facets, stats, pagination?, export_truncated?}. */
    public static function envelope(Collection $items, ?LengthAwarePaginator $page, array $facets, array $stats, bool $export): array
    {
        $data = ['items' => $items->values(), 'facets' => $facets, 'stats' => $stats];
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }
        if ($export) {
            $data['export_truncated'] = false;
        }

        return $data;
    }
}
