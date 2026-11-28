<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The same request language as ListQuery (page, per_page, search, filters[key][], filters[date]=from~to, sort, export),
 * for lists that are assembled from several tables and so cannot be one database query (a program's money ledger, its
 * activity feed). The rows are light, and bounded by what one program can have, so they are searched, filtered, sorted
 * and paged in memory; anything one table can answer should use ListQuery instead.
 *
 *   $list = CollectionList::for($rows, $request)
 *       ->search(fn ($r) => $r['label'].' '.$r['reference'])
 *       ->filter('kind', fn ($r) => $r['kind'])
 *       ->dateRange('date', fn ($r) => $r['at'])        // a string or Carbon
 *       ->sortable(['date' => fn ($r) => $r['at'], 'amount' => fn ($r) => $r['amount']], default: '-date');
 *   [$items, $page] = $list->fetch();
 *
 * Described filters (sent to the frontend panel as `data.filter_schema` via filterSchema(), like ListQuery):
 *   ->options('kind', fn ($r) => $r['kind'], label: 'app:type')   (the value may be a list: a row matches any of its values)
 *   ->dateRange('date', fn ($r) => $r['at'], label: 'app:date')
 *   ->numberRange('amount', fn ($r) => $r['amount'], label: 'app:amount')
 *   ->boolean('paid', fn ($r) => $r['paid'], label: 'app:paid')
 */
class CollectionList
{
    /** @var array<string, callable> */
    private array $filters = [];
    /** @var array<string, callable> */
    private array $ranges = [];
    /** @var array<string, callable> */
    private array $numbers = [];
    /** @var array<string, callable> */
    private array $booleans = [];
    /** @var array<string, array> */
    private array $described = [];
    /** @var array<string, callable> */
    private array $sorts = [];
    private ?string $defaultSort = null;
    private $haystack = null;

    private function __construct(private Collection $rows, private Request $request)
    {
    }

    public static function for(iterable $rows, Request $request): static
    {
        return new static(collect($rows), $request);
    }

    public function search(callable $haystack): static
    {
        $this->haystack = $haystack;

        return $this;
    }

    public function filter(string $key, callable $value): static
    {
        $this->filters[$key] = $value;

        return $this;
    }

    public function dateRange(string $key, callable $value, ?string $label = null, array|bool $presets = true, ?string $fallback = null): static
    {
        $this->ranges[$key] = $value;
        if ($label !== null || $fallback !== null) {
            $this->describe($key, 'dateRange', $label, $fallback, ['presets' => $presets === true ? ListQuery::DATE_PRESETS : ($presets ?: [])]);
        }

        return $this;
    }

    /** A multi-select filter described in filter_schema; $optionLabels and $values work as in ListQuery::options(). */
    public function options(string $key, callable $value, ?string $label = null, array|\Closure|null $optionLabels = null, ?array $values = null, ?bool $searchable = null, ?string $fallback = null): static
    {
        $this->filters[$key] = $value;
        $this->describe($key, 'options', $label, $fallback, ['labels' => $optionLabels, 'values' => $values, 'searchable' => $searchable]);

        return $this;
    }

    /** Numbers min~max over fn ($row) => number. */
    public function numberRange(string $key, callable $value, ?string $label = null, ?string $fallback = null): static
    {
        $this->numbers[$key] = $value;
        $this->describe($key, 'numberRange', $label, $fallback);

        return $this;
    }

    /** Yes / No / Any over fn ($row) => bool. */
    public function boolean(string $key, callable $value, ?string $label = null, ?string $fallback = null): static
    {
        $this->booleans[$key] = $value;
        $this->describe($key, 'boolean', $label, $fallback);

        return $this;
    }

    /** The described filters with counts and bounds (each with the search and the OTHER filters applied); see ListQuery. */
    public function filterSchema(): array
    {
        if ($this->wantsExport()) {
            return [];
        }

        $out = [];
        foreach ($this->described as $key => $d) {
            $entry = ['key' => $key, 'type' => $d['type'], 'label' => $d['label'], 'fallback' => $d['fallback']];
            if ($d['type'] === 'options') {
                $entry['options'] = ListQuery::optionList($this->facets([$key])[$key] ?? [], $d['values'], $d['labels']);
                $entry['searchable'] = $d['searchable'] ?? count($entry['options']) > ListQuery::SEARCHABLE_AFTER;
            } elseif ($d['type'] === 'dateRange') {
                $entry['presets'] = array_values($d['presets']);
            } elseif ($d['type'] === 'numberRange') {
                $values = $this->matching($key)->map(fn ($row) => ($this->numbers[$key])($row))->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (float) $v);
                $number = fn ($v) => floor($v) == $v ? (int) $v : round($v, 2);
                if ($values->isNotEmpty()) {
                    $entry['min'] = $number($values->min());
                    $entry['max'] = $number($values->max());
                }
            } elseif ($d['type'] === 'boolean') {
                $rows = $this->matching($key);
                $yes = $rows->filter(fn ($row) => (bool) ($this->booleans[$key])($row))->count();
                $entry['options'] = [['value' => 'yes', 'count' => $yes], ['value' => 'no', 'count' => $rows->count() - $yes]];
            }
            $out[] = $entry;
        }

        return $out;
    }

    private function describe(string $key, string $type, ?string $label, ?string $fallback, array $extra = []): void
    {
        $this->described[$key] = ['type' => $type, 'label' => $label, 'fallback' => $fallback ?? ucfirst(str_replace(['_', '-'], ' ', $key))] + $extra;
    }

    public function sortable(array $sorts, ?string $default = null): static
    {
        $this->sorts = $sorts;
        $this->defaultSort = $default;

        return $this;
    }

    public function wantsExport(): bool
    {
        return $this->request->boolean('export');
    }

    /** Rows matching the search and every filter except $except (used for the option counts). */
    public function matching(?string $except = null): Collection
    {
        $words = ListQuery::words($this->request->query('search'));
        $given = $this->request->query('filters', []);
        $given = is_array($given) ? $given : [];

        return $this->rows->filter(function ($row) use ($words, $given, $except) {
            if ($words && $this->haystack) {
                $text = mb_strtolower((string) ($this->haystack)($row));
                foreach ($words as $word) {
                    if (! str_contains($text, $word)) {
                        return false;
                    }
                }
            }

            foreach ($given as $key => $value) {
                if ($key === $except) {
                    continue;
                }
                if (isset($this->numbers[$key])) {
                    [$min, $max] = ListQuery::numberBounds($value);
                    $number = ($this->numbers[$key])($row);
                    if (($min !== null || $max !== null) && ! is_numeric($number)) {
                        return false;
                    }
                    if (($min !== null && (float) $number < $min) || ($max !== null && (float) $number > $max)) {
                        return false;
                    }
                    continue;
                }
                if (isset($this->booleans[$key])) {
                    $yes = ListQuery::yesNo($value);
                    if ($yes !== null && (bool) ($this->booleans[$key])($row) !== $yes) {
                        return false;
                    }
                    continue;
                }
                if (isset($this->ranges[$key])) {
                    [$from, $to] = ListQuery::splitRange($value);
                    $day = substr((string) ($this->ranges[$key])($row), 0, 10);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && ($day === '' || $day < $from)) {
                        return false;
                    }
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && ($day === '' || $day > $to)) {
                        return false;
                    }
                    continue;
                }
                if (! isset($this->filters[$key])) {
                    continue;
                }
                $values = array_map('strval', array_values(array_filter((array) $value, fn ($v) => is_scalar($v) && $v !== '')));
                // a value may be a list (a row with several Business Coaches): the row matches when any of them is picked
                $own = array_map('strval', (array) ($this->filters[$key])($row));
                if ($values && ! array_intersect($own, $values)) {
                    return false;
                }
            }

            return true;
        });
    }

    /** @return array{0: Collection, 1: LengthAwarePaginator|null} */
    public function fetch(): array
    {
        $rows = $this->sorted($this->matching());

        if ($this->wantsExport()) {
            return [$rows->values(), null];
        }
        if (! ApiContract::wantsPagination($this->request)) {
            return [$rows->values(), null];
        }

        $perPage = ApiContract::perPage($this->request);
        $page = max(1, (int) $this->request->query('page', 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page);

        return [$paginator->getCollection(), $paginator];
    }

    /** Exports are never cut (every matching row is returned); kept so responses still carry `export_truncated: false`. */
    public function exportTruncated(): bool
    {
        return false;
    }

    /**
     * Option counts per filter key, each with the search and the OTHER filters applied.
     *
     * @return array<string, list<array{value: mixed, count: int}>>
     */
    public function facets(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (! isset($this->filters[$key])) {
                continue;
            }
            $out[$key] = $this->matching($key)
                ->flatMap(fn ($row) => (array) ($this->filters[$key])($row))
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->countBy()
                ->map(fn ($count, $value) => ['value' => $value, 'count' => $count])
                ->sortByDesc('count')
                ->values()
                ->take(100)
                ->all();
        }

        return $out;
    }

    private function sorted(Collection $rows): Collection
    {
        $requested = (string) $this->request->query('sort', '');
        $sort = $requested !== '' ? $requested : (string) $this->defaultSort;
        $key = ltrim($sort, '-');
        if (! isset($this->sorts[$key])) {
            $sort = (string) $this->defaultSort;
            $key = ltrim($sort, '-');
        }
        if (! isset($this->sorts[$key])) {
            return $rows;
        }

        $descending = str_starts_with($sort, '-');

        // values sort as numbers when both are numbers, otherwise as text; the original order breaks ties, so pages are stable
        return $rows->values()->map(fn ($row, $index) => [$row, $index])
            ->sort(function ($a, $b) use ($key, $descending) {
                $x = ($this->sorts[$key])($a[0]);
                $y = ($this->sorts[$key])($b[0]);
                $cmp = is_numeric($x) && is_numeric($y) ? $x <=> $y : strcasecmp((string) $x, (string) $y);

                return ($descending ? -$cmp : $cmp) ?: $a[1] <=> $b[1];
            })
            ->map(fn ($pair) => $pair[0])
            ->values();
    }
}
