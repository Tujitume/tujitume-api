<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The response shapes the frontend can rely on.
 *
 *   list    {success, message, data: {items: [...], pagination?: {...}}}
 *   single  {success, message, data: {<key>: {...}}}
 *   status  {value, label, color}   color is one of success|warning|danger|info|neutral
 *
 * Existing endpoints keep their old top-level keys ("programs", "program_data" ...) next to the
 * new envelope while the app migrates, so nothing that reads them breaks.
 */
class ApiContract
{
    public const DEFAULT_PER_PAGE = 15;
    public const MAX_PER_PAGE = 100;
    public const FALLBACK_COLOR = 'neutral';

    /** {value, label, color} for a status, colours come from config/status.php. */
    public static function statusMeta(string $group, ?string $value): array
    {
        $value = (string) $value;
        $color = config("status.{$group}.{$value}", self::FALLBACK_COLOR);

        return [
            'value' => $value,
            'label' => Str::headline($value),
            'color' => $color === 'gray' ? self::FALLBACK_COLOR : $color,
        ];
    }

    /** Pagination is opt-in (?page= or ?per_page=) so existing full-list consumers keep working. */
    public static function wantsPagination(Request $request): bool
    {
        return $request->filled('page') || $request->filled('per_page');
    }

    public static function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        return min(max($requested, 1), self::MAX_PER_PAGE);
    }

    /**
     * Run a list query: a page when the client asked for one, otherwise everything.
     *
     * @return array{0: Collection, 1: LengthAwarePaginator|null}
     */
    public static function fetch(Builder $query, Request $request): array
    {
        if (! self::wantsPagination($request)) {
            return [$query->get(), null];
        }

        $page = $query->paginate(self::perPage($request));

        return [$page->getCollection(), $page];
    }

    public static function pagination(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'per_page'     => $page->perPage(),
            'total'        => $page->total(),
            'last_page'    => $page->lastPage(),
            'from'         => $page->firstItem(),
            'to'           => $page->lastItem(),
        ];
    }

    /**
     * List envelope. $legacy maps old top-level keys to the same items, e.g. ["programs"].
     */
    public static function listResponse(string $message, iterable $items, ?LengthAwarePaginator $page = null, array $legacy = []): JsonResponse
    {
        $items = $items instanceof Collection ? $items->values() : collect($items)->values();

        $data = ['items' => $items];
        if ($page) $data['pagination'] = self::pagination($page);

        $body = ['success' => true, 'message' => $message, 'data' => $data];
        foreach ($legacy as $key) $body[$key] = $items;

        return response()->json($body);
    }

    /**
     * Single-item envelope. $extra is merged into data and $legacy into the top level.
     */
    public static function itemResponse(string $message, string $key, mixed $item, array $extra = [], array $legacy = [], int $status = 200): JsonResponse
    {
        return response()->json(
            ['success' => true, 'message' => $message, 'data' => [$key => $item] + $extra] + $legacy,
            $status
        );
    }
}
