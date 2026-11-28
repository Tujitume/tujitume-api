<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HOW TO ADD A FILTER (one line; the frontend panel, chips, URL state and export pick it up from `data.filter_schema`):
 *
 *   ->options('status', 'status', label: 'app:status')                          multi-select, counts per value
 *   ->entity('coach', 'reviewer_id', User::class, 'first_name', label: 'app:x')  ids shown by name, searchable
 *   ->dateRange('created', 'created_at', label: 'app:createdOn')                 from~to with quick presets
 *   ->numberRange('fee', 'fee_amount', label: 'app:fee')                         min~max, bounds from the data
 *   ->boolean('overdue', fn ($q, bool $yes) => ..., label: 'app:overdue')        Yes / No / Any
 *
 * then put `'filter_schema' => $list->filterSchema()` in the response's `data` (next to items / facets / stats).
 * `label` is an i18n key ("namespace:key") the frontend translates; `fallback` is the text shown when the key is missing
 * (default: the key in words). Old `filter()` / `dateRange()` calls without a label still filter, they are just not
 * described in the schema (their page keeps building its own panel).
 *
 * One way for every list endpoint to search, filter, sort and page on the database, so a table never has to load
 * everything to show ten rows.
 *
 * Query string the frontend sends (all optional):
 *   page, per_page          page of rows (per_page is capped at ApiContract::MAX_PER_PAGE)
 *   search                  words; EVERY word must appear in at least one of the searchable columns (any case, any order)
 *   filters[key][]=value    a value list per filter; a row matches when it has ANY of a key's values; keys combine with AND
 *   filters[key]=from~to    a date range (dateRange) or a number range (numberRange, "10~", "~500", "10~500")
 *   filters[key]=yes|no     a boolean filter (also 1/0, true/false; both or neither = any)
 *   sort=-created_at        column to sort by, "-" for descending (only registered sort keys are accepted)
 *   export=1                every matching row (no cap) instead of a page, for downloading the table
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
 *
 * filter_schema entries: {key, type: options|dateRange|numberRange|boolean, label, fallback,
 *   options?: [{value, label?, count}], searchable?, min?, max?, presets?}
 */
class ListQuery
{
    public const MAX_SEARCH_WORDS = 6;
    public const MAX_SEARCH_LENGTH = 100;

    /** Quick ranges a date filter offers by default (the frontend's calendar preset keys). */
    public const DATE_PRESETS = ['today', 'yesterday', 'last7Days', 'last30Days', 'thisMonth', 'lastMonth', 'thisYear'];
    /** Option lists longer than this get a search box unless the filter says otherwise. */
    public const SEARCHABLE_AFTER = 8;

    /** @var array<string, string|Expression|Closure> */
    private array $filters = [];
    /** @var array<string, string|Closure> */
    private array $ranges = [];
    /** @var array<string, string|Expression|Closure> */
    private array $numbers = [];
    /** @var array<string, string|Closure> */
    private array $booleans = [];
    /** @var array<string, array> the filters described in filter_schema, in the order they were registered */
    private array $described = [];
    /** @var array<string, array|null> option counts already worked out for this request */
    private array $counts = [];
    /** @var list<string> */
    private array $searchColumns = [];
    /** @var array<string, string|Closure> */
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
    public function filter(string $key, string|Expression|Closure $column): static
    {
        $this->filters[$key] = $column;

        return $this;
    }

    /**
     * Dates from~to (whole days). $column is a datetime column, or fn (Builder $q, ?string $from, ?string $to) for a column
     * that needs its own comparison (a day kept as text). With a label it is described in filter_schema, with $presets
     * (true = DATE_PRESETS, or a list of preset keys such as ['next7Days', 'next30Days']).
     */
    public function dateRange(string $key, string|Closure $column, ?string $label = null, array|bool $presets = true, ?string $fallback = null): static
    {
        $this->ranges[$key] = $column;
        if ($label !== null || $fallback !== null) {
            $this->describe($key, 'dateRange', $label, $fallback, ['presets' => $presets === true ? self::DATE_PRESETS : ($presets ?: [])]);
        }

        return $this;
    }

    /**
     * A multi-select filter described in filter_schema, with a count per value.
     *   $column        a column, an expression (DB::raw), or fn (Builder $q, array $values)
     *   $optionLabels  [value => label] or fn (array $values): [value => label]; a label may be an i18n key ("app:paid")
     *   $values        known values in display order (shown even when nothing has them yet); others follow, most used first
     *   $countBy       what to count by when $column is a closure: a column, an expression, or fn (Builder $q): [{value, count}]
     *                  given the query with the search and the other filters applied; without it there are no counts
     */
    public function options(
        string $key, string|Expression|Closure $column, ?string $label = null, array|Closure|null $optionLabels = null,
        ?array $values = null, string|Expression|Closure|null $countBy = null, ?bool $searchable = null, ?string $fallback = null,
    ): static {
        $this->filters[$key] = $column;
        $this->describe($key, 'options', $label, $fallback, [
            'labels' => $optionLabels, 'values' => $values, 'countBy' => $countBy ?? ($column instanceof Closure ? null : $column), 'searchable' => $searchable,
        ]);

        return $this;
    }

    /**
     * Options whose values are ids of another table, shown by name (a program, a round, a Business Coach). $names is a
     * model class (its $title column is the name) or fn (array $ids): [id => name]. Searchable by default.
     */
    public function entity(string $key, string $column, string|Closure $names, string $title = 'name', ?string $label = null, ?bool $searchable = true, ?string $fallback = null): static
    {
        $labels = $names instanceof Closure ? $names : function (array $ids) use ($names, $title) {
            $model = new $names();

            return $names::query()->whereIn($model->getKeyName(), $ids)->pluck($title, $model->getKeyName())->all();
        };

        return $this->options($key, $column, $label, $labels, searchable: $searchable, fallback: $fallback);
    }

    /** Numbers min~max (either side may be empty). $column is a column, an expression, or fn (Builder $q, ?float $min, ?float $max). */
    public function numberRange(string $key, string|Expression|Closure $column, ?string $label = null, ?string $fallback = null): static
    {
        $this->numbers[$key] = $column;
        $this->describe($key, 'numberRange', $label, $fallback);

        return $this;
    }

    /** Yes / No / Any. $column is a true/false column, or fn (Builder $q, bool $yes) that keeps the rows that are (or are not). */
    public function boolean(string $key, string|Closure $column, ?string $label = null, ?string $fallback = null): static
    {
        $this->booleans[$key] = $column;
        $this->describe($key, 'boolean', $label, $fallback);

        return $this;
    }

    /**
     * Sort keys (the column names a client may sort by); a key => column map lets the public name differ from the column.
     * The column may be fn (Builder $q, string $direction) to sort your own way (a related table's column, a subquery).
     */
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

    /** Rows per block when an export is fetched page by page */
    public const EXPORT_CHUNK = 2000;

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
            if (empty($q->getQuery()->orders)) {
                $q->orderBy($q->getModel()->qualifyColumn($q->getModel()->getKeyName())); // chunks need a fixed order
            }
            // export=1&page=N: one block of EXPORT_CHUNK rows with pagination, so the client fetches block after block and
            // a big table (tens of thousands of rows) never has to fit in one response or one PHP process
            if ($this->request->has('page')) {
                $page = $q->paginate(self::EXPORT_CHUNK, ['*'], 'page', max(1, (int) $this->request->query('page', 1)));

                return [$page->getCollection(), $page];
            }

            // export=1 alone: every matching row, read in chunks so the driver never buffers one huge result set
            return [$q->lazy(500)->collect(), null];
        }
        if (! ApiContract::wantsPagination($this->request)) {
            return [$q->get(), null];
        }

        $page = $q->paginate(ApiContract::perPage($this->request), ['*'], 'page', max(1, (int) $this->request->query('page', 1)));

        return [$page->getCollection(), $page];
    }

    /**
     * Exports are never cut: every matching row is returned. Kept (always false) so responses still carry
     * `export_truncated` and the frontend contract stays the same.
     */
    public function exportTruncated(): bool
    {
        return false;
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
            $counted = $this->counted($key);
            if ($counted !== null) {
                $out[$key] = $counted;
            }
        }

        return $out;
    }

    /**
     * The described filters for the frontend panel (see the note at the top), with option counts and number bounds
     * worked out with the search and every OTHER filter applied. Empty for an export (nobody shows a panel for it).
     *
     * @return list<array<string, mixed>>
     */
    public function filterSchema(): array
    {
        if ($this->wantsExport()) {
            return [];
        }

        $out = [];
        foreach ($this->described as $key => $d) {
            $entry = ['key' => $key, 'type' => $d['type'], 'label' => $d['label'], 'fallback' => $d['fallback']];
            if ($d['type'] === 'options') {
                $entry['options'] = self::optionList($this->counted($key), $d['values'], $d['labels']);
                $entry['searchable'] = $d['searchable'] ?? count($entry['options']) > self::SEARCHABLE_AFTER;
            } elseif ($d['type'] === 'dateRange') {
                $entry['presets'] = array_values($d['presets']);
            } elseif ($d['type'] === 'numberRange') {
                $entry += $this->bounds($key);
            } elseif ($d['type'] === 'boolean') {
                $entry['options'] = [
                    ['value' => 'yes', 'count' => $this->countBoolean($key, true)],
                    ['value' => 'no', 'count' => $this->countBoolean($key, false)],
                ];
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * [{value, label?, count}]: the known $values first (in their order, 0 when nothing has them), then the counted ones.
     * $counted null means "not countable" (each option's count is null).
     */
    public static function optionList(?array $counted, ?array $values, array|Closure|null $labels): array
    {
        $counts = [];
        foreach ($counted ?? [] as $row) {
            $counts[(string) $row['value']] = (int) $row['count'];
        }
        $all = array_values(array_unique(array_map('strval', [...($values ?? []), ...array_keys($counts)])));
        $names = $labels instanceof Closure ? ($all ? $labels($all) : []) : ($labels ?? []);

        return array_map(function (string $value) use ($counts, $counted, $names) {
            $option = ['value' => $value, 'count' => $counted === null ? null : ($counts[$value] ?? 0)];
            $label = $names[$value] ?? null;
            if ($label !== null && $label !== '') {
                $option['label'] = (string) $label;
            }

            return $option;
        }, $all);
    }

    /** "from~to" → [from, to] as given text (either may be ''). */
    public static function splitRange(mixed $value): array
    {
        $text = (string) (is_array($value) ? reset($value) : $value);

        return array_pad(explode('~', $text, 2), 2, '');
    }

    /** "10~500" → [10.0, 500.0]; a side that is not a plain number is null. */
    public static function numberBounds(mixed $value): array
    {
        return array_map(fn ($side) => preg_match('/^\s*-?\d+(\.\d+)?\s*$/', $side) ? (float) $side : null, self::splitRange($value));
    }

    /** yes / no from a boolean filter value (array or text); null for "any" (nothing, both, or something else). */
    public static function yesNo(mixed $value): ?bool
    {
        $picked = [];
        foreach ((array) $value as $v) {
            $v = is_scalar($v) ? strtolower(trim((string) $v)) : '';
            if (in_array($v, ['yes', '1', 'true'], true)) {
                $picked[true] = true;
            } elseif (in_array($v, ['no', '0', 'false'], true)) {
                $picked[0] = false;
            }
        }

        return count($picked) === 1 ? reset($picked) : null;
    }

    // ── internals ─────────────────────────────────────────────────────────────────────────────────

    private function describe(string $key, string $type, ?string $label, ?string $fallback, array $extra = []): void
    {
        $this->described[$key] = ['type' => $type, 'label' => $label, 'fallback' => $fallback ?? ucfirst(str_replace(['_', '-'], ' ', $key))] + $extra;
    }

    private function sql(Builder $q, string|Expression $column): string
    {
        $grammar = $q->getQuery()->getGrammar();

        return $column instanceof Expression ? (string) $column->getValue($grammar) : $grammar->wrap($q->getModel()->qualifyColumn($column));
    }

    /** [{value, count}] for an options filter (null when it cannot be counted), worked out once per request. */
    private function counted(string $key): ?array
    {
        if (array_key_exists($key, $this->counts)) {
            return $this->counts[$key];
        }
        // a described option filter counts by its countBy (which may be a counting closure); a plain filter() only by its column
        $plain = $this->filters[$key] ?? null;
        $by = array_key_exists('countBy', $this->described[$key] ?? []) ? $this->described[$key]['countBy'] : ($plain instanceof Closure ? null : $plain);

        return $this->counts[$key] = match (true) {
            $by instanceof Closure => array_values($by($this->query($key, sorted: false)->reorder())),
            is_string($by), $by instanceof Expression => $this->countValues($key, $by),
            default => null,
        };
    }

    /** @return list<array{value: mixed, count: int}> the 100 most used values of $column, with the other filters applied */
    private function countValues(string $key, string|Expression $column): array
    {
        $q = $this->query($key, sorted: false)->reorder();
        $sql = $this->sql($q, $column);

        return $q->whereRaw($sql.' is not null')
            ->select(DB::raw($sql.' as value'), DB::raw('count(*) as count'))
            ->groupBy(DB::raw($sql))
            ->orderByDesc('count')
            ->limit(100)
            ->toBase()
            ->get()
            ->map(fn ($row) => ['value' => $row->value, 'count' => (int) $row->count])
            ->all();
    }

    /** {min, max} of a number filter's column over the rows the other filters leave (none for a closure filter or no rows). */
    private function bounds(string $key): array
    {
        $column = $this->numbers[$key];
        if ($column instanceof Closure) {
            return [];
        }
        $q = $this->query($key, sorted: false)->reorder();
        $sql = '('.$this->sql($q, $column).' + 0)';
        // select([]) first: a base query with withCount / withSum columns must not mix them into the aggregate
        $row = $q->toBase()->select([])->selectRaw("MIN($sql) as lo, MAX($sql) as hi")->first();
        $number = fn ($v) => $v === null ? null : (floor((float) $v) == (float) $v ? (int) $v : round((float) $v, 2));

        return array_filter(['min' => $number($row->lo ?? null), 'max' => $number($row->hi ?? null)], fn ($v) => $v !== null);
    }

    private function countBoolean(string $key, bool $yes): int
    {
        $q = $this->query($key, sorted: false)->reorder();
        $this->applyBoolean($q, $this->booleans[$key], $yes);

        return $q->toBase()->getCountForPagination();
    }

    private function applyBoolean(Builder $q, string|Closure $column, bool $yes): void
    {
        if ($column instanceof Closure) {
            $column($q, $yes);

            return;
        }
        $qualified = $q->getModel()->qualifyColumn($column);
        $yes ? $q->where($qualified, true) : $q->where(fn (Builder $no) => $no->whereNull($qualified)->orWhere($qualified, false));
    }

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
                [$from, $to] = self::splitRange($value);
                $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : null;
                $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : null;
                $target = $this->ranges[$key];
                if ($target instanceof Closure) {
                    if ($from || $to) {
                        $target($q, $from, $to);
                    }
                    continue;
                }
                $column = $q->getModel()->qualifyColumn($target);
                if ($from) {
                    $q->where($column, '>=', $from.' 00:00:00');
                }
                if ($to) {
                    $q->where($column, '<=', $to.' 23:59:59');
                }
                continue;
            }
            if (isset($this->numbers[$key])) {
                [$min, $max] = self::numberBounds($value);
                $target = $this->numbers[$key];
                if ($target instanceof Closure) {
                    if ($min !== null || $max !== null) {
                        $target($q, $min, $max);
                    }
                    continue;
                }
                $sql = '('.$this->sql($q, $target).' + 0)'; // compared as a number even when the column holds text
                if ($min !== null) {
                    $q->whereRaw("$sql >= (? + 0)", [$min]);
                }
                if ($max !== null) {
                    $q->whereRaw("$sql <= (? + 0)", [$max]);
                }
                continue;
            }
            if (isset($this->booleans[$key])) {
                if (($yes = self::yesNo($value)) !== null) {
                    $this->applyBoolean($q, $this->booleans[$key], $yes);
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
            } elseif ($target instanceof Expression) {
                $q->whereIn($target, $values);
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

        $target = $this->sorts[$key];
        if ($target instanceof Closure) {
            $target($q, $direction);
        } else {
            $q->orderBy($q->getModel()->qualifyColumn($target), $direction);
        }
        // a stable order, so a row never shows on two pages (or none) while the first sort column has ties
        $q->orderBy($q->getModel()->qualifyColumn($q->getModel()->getKeyName()), $direction);
    }
}
