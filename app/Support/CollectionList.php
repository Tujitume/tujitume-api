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
 */
class CollectionList
{
    /** @var array<string, callable> */
    private array $filters = [];
    /** @var array<string, callable> */
    private array $ranges = [];
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

    public function dateRange(string $key, callable $value): static
    {
        $this->ranges[$key] = $value;

        return $this;
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
                if (isset($this->ranges[$key])) {
                    [$from, $to] = array_pad(explode('~', (string) (is_array($value) ? reset($value) : $value), 2), 2, '');
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
                if ($values && ! in_array((string) ($this->filters[$key])($row), $values, true)) {
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
            return [$rows->take(ListQuery::EXPORT_CAP)->values(), null];
        }
        if (! ApiContract::wantsPagination($this->request)) {
            return [$rows->values(), null];
        }

        $perPage = ApiContract::perPage($this->request);
        $page = max(1, (int) $this->request->query('page', 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page);

        return [$paginator->getCollection(), $paginator];
    }

    public function exportTruncated(): bool
    {
        return $this->wantsExport() && $this->matching()->count() > ListQuery::EXPORT_CAP;
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
                ->map(fn ($row) => ($this->filters[$key])($row))
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
