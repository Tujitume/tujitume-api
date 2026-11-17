<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One way for every list endpoint to search, filter, sort and page on the database, so a table never has to load
 * everything to show ten rows.
 *
 * Query string the frontend sends (all optional):
 *   page, per_page          page of rows (per_page is capped at ApiContract::MAX_PER_PAGE)
 *   search                  words; EVERY word must appear in at least one of the searchable columns (any case, any order)
 *   filters[key][]=value    a value list per filter; a row matches when it has ANY of a key's values; keys combine with AND
 *   filters[key]=from~to    a date range for filters registered with dateRange()
 *   sort=-created_at        column to sort by, "-" for descending (only registered sort keys are accepted)
 *   export=1                every matching row (up to EXPORT_CAP) instead of a page, for downloading the table
 *
 * Only columns the endpoint registers are ever used, so nothing the client sends reaches the SQL as an identifier.
 *
 *   $list = ListQuery::for($query, $request)
 *       ->search(['startup_name', 'contact_person_name', 'user.email'])
 *       ->filter('status', 'status')
 *       ->dateRange('created', 'created_at')
 *       ->sortable(['created_at', 'startup_name'], default: '-created_at');
 *   [$rows, $page] = $list->fetch();
 *   $facets = $list->facets(['status']);   // [{value, count}] per filter, counted with the OTHER filters applied
 */
class ListQuery
{
    public const EXPORT_CAP = 5000;
    public const MAX_SEARCH_WORDS = 6;
    public const MAX_SEARCH_LENGTH = 100;

    /** @var array<string, string|Closure> */
    private array $filters = [];
    /** @var array<string, string> */
    private array $ranges = [];
    /** @var list<string> */
    private array $searchColumns = [];
    /** @var array<string, string> */
    private array $sorts = [];
    private ?string $defaultSort = null;

    private function __construct(private Builder $query, private Request $request)
    {
    }

    public static function for(Builder $query, Request $request): static
    {
        return new static($query, $request);
    }

    /** Columns the search looks in. "relation.column" searches a related table (any related row matching counts). */
    public function search(array $columns): static
    {
        $this->searchColumns = array_values($columns);

        return $this;
    }

    /** $column is a column name, or fn (Builder $q, array $values) to apply the values your own way (joins, derived states). */
    public function filter(string $key, string|Closure $column): static
    {
        $this->filters[$key] = $column;

        return $this;
    }

    public function dateRange(string $key, string $column): static
    {
        $this->ranges[$key] = $column;

        return $this;
    }

    /** Sort keys (the column names a client may sort by); a key => column map lets the public name differ from the column. */
    public function sortable(array $keys, ?string $default = null): static
    {
        foreach ($keys as $key => $column) {
            $this->sorts[is_int($key) ? $column : $key] = $column;
        }
        $this->defaultSort = $default;

        return $this;
    }

    public function wantsExport(): bool
    {
        return $this->request->boolean('export');
    }

    /** The query with the search, filters and sort of this request applied. */
    public function query(?string $exceptFilter = null, bool $sorted = true): Builder
    {
        $q = clone $this->query;
        $this->applySearch($q);
        $this->applyFilters($q, $exceptFilter);
        if ($sorted) {
            $this->applySort($q);
        }

        return $q;
    }

    /**
     * Rows for this request: a page (page / per_page), every match when exporting, or everything when the client asked
     * for neither (so existing full-list consumers keep working while pages are migrated one by one).
     *
     * @return array{0: Collection, 1: LengthAwarePaginator|null}
     */
    public function fetch(): array
    {
        $q = $this->query();

        if ($this->wantsExport()) {
            return [$q->limit(self::EXPORT_CAP)->get(), null];
        }
        if (! ApiContract::wantsPagination($this->request)) {
            return [$q->get(), null];
        }

        $page = $q->paginate(ApiContract::perPage($this->request), ['*'], 'page', max(1, (int) $this->request->query('page', 1)));

        return [$page->getCollection(), $page];
    }

    /** Whether the export was cut at EXPORT_CAP (the client tells the person so instead of silently dropping rows). */
    public function exportTruncated(): bool
    {
        return $this->wantsExport() && $this->query(sorted: false)->toBase()->getCountForPagination() > self::EXPORT_CAP;
    }

    /**
     * Option counts for the filter popover: for each key, how many rows have each value, counting with the search and
     * every OTHER filter applied (so picking "Approved" still shows how many "Rejected" there would be).
     * Only plain-column filters can be counted here.
     *
     * @return array<string, list<array{value: mixed, count: int}>>
     */
    public function facets(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $column = $this->filters[$key] ?? null;
            if (! is_string($column)) {
                continue;
            }
            $qualified = $this->query->getModel()->qualifyColumn($column);
            $out[$key] = $this->query($key, sorted: false)
                ->reorder()
                ->whereNotNull($qualified)
                ->select($qualified.' as value', DB::raw('count(*) as count'))
                ->groupBy($qualified)
                ->orderByDesc('count')
                ->limit(100)
                ->get()
                ->map(fn ($row) => ['value' => $row->value, 'count' => (int) $row->count])
                ->all();
        }

        return $out;
    }

    // ── internals ─────────────────────────────────────────────────────────────────────────────────

    /** The words of the search: trimmed, lower-case, at most MAX_SEARCH_WORDS, none empty. */
    public static function words(?string $search): array
    {
        $text = mb_strtolower(trim((string) $search));
        $text = mb_substr($text, 0, self::MAX_SEARCH_LENGTH);
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($words)), 0, self::MAX_SEARCH_WORDS);
    }

    /** %, _ and ! typed by the person are text, not wildcards (queries use `escape '!'`, which MySQL and SQLite both accept). */
    public static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function applySearch(Builder $q): void
    {
        $words = self::words($this->request->query('search'));
        if (! $words || ! $this->searchColumns) {
            return;
        }

        foreach ($words as $word) {
            $like = '%'.self::escapeLike($word).'%';
            $q->where(function (Builder $group) use ($like, $q) {
                foreach ($this->searchColumns as $column) {
                    if (str_contains($column, '.')) {
                        [$relation, $field] = explode('.', $column, 2);
                        $group->orWhereHas($relation, fn (Builder $r) => $r->whereRaw($r->getGrammar()->wrap($r->getModel()->qualifyColumn($field))." like ? escape '!'", [$like]));
                    } else {
                        $group->orWhereRaw($q->getQuery()->getGrammar()->wrap($q->getModel()->qualifyColumn($column))." like ? escape '!'", [$like]);
                    }
                }
            });
        }
    }

    private function applyFilters(Builder $q, ?string $except): void
    {
        $given = $this->request->query('filters', []);
        if (! is_array($given)) {
            return;
        }

        foreach ($given as $key => $value) {
            if ($key === $except) {
                continue;
            }
            if (isset($this->ranges[$key])) {
                [$from, $to] = array_pad(explode('~', (string) (is_array($value) ? reset($value) : $value), 2), 2, '');
                $column = $q->getModel()->qualifyColumn($this->ranges[$key]);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                    $q->where($column, '>=', $from.' 00:00:00');
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                    $q->where($column, '<=', $to.' 23:59:59');
                }
                continue;
            }
            if (! isset($this->filters[$key])) {
                continue; // an unknown filter is ignored, never turned into SQL
            }

            $values = array_values(array_filter((array) $value, fn ($v) => is_scalar($v) && $v !== ''));
            if (! $values) {
                continue;
            }

            $target = $this->filters[$key];
            if ($target instanceof Closure) {
                $target($q, $values);
            } else {
                $q->whereIn($q->getModel()->qualifyColumn($target), $values);
            }
        }
    }

    private function applySort(Builder $q): void
    {
        $requested = (string) $this->request->query('sort', '');
        $sort = $requested !== '' ? $requested : (string) $this->defaultSort;
        if ($sort === '') {
            return;
        }

        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');
        if (! isset($this->sorts[$key])) {
            // an unknown sort falls back to the default instead of failing the whole list
            if ($requested !== '' && $this->defaultSort) {
                $direction = str_starts_with($this->defaultSort, '-') ? 'desc' : 'asc';
                $key = ltrim($this->defaultSort, '-');
            }
            if (! isset($this->sorts[$key])) {
                return;
            }
        }

        $q->orderBy($q->getModel()->qualifyColumn($this->sorts[$key]), $direction);
        // a stable order, so a row never shows on two pages (or none) while the first sort column has ties
        $q->orderBy($q->getModel()->qualifyColumn($q->getModel()->getKeyName()), $direction);
    }
}
