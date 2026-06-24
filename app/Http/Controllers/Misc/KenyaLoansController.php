<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class KenyaLoansController extends Controller
{
    private function fetchJsonOrFail(?string $url, int $timeoutSeconds = 8): array
    {
        if (!$url) {
            throw new \RuntimeException('DATA_URL_NOT_CONFIGURED');
        }
        $resp = Http::timeout($timeoutSeconds)->get($url);
        if (!$resp->ok()) {
            throw new \RuntimeException('REMOTE_ERROR:' . $resp->status());
        }
        $json = $resp->json();
        if (!is_array($json)) {
            throw new \RuntimeException('INVALID_JSON');
        }
        return $json;
    }

    private function loadLenders(): array
    {
        // Try local file first (with status data from cron)
        $localPath = resource_path('kenya_lenders.json');
        if (file_exists($localPath)) {
            $localData = json_decode(file_get_contents($localPath), true);
            if (is_array($localData['lenders'] ?? null)) {
                return $localData['lenders'];
            }
        }

        // Fallback to remote URL
        $json = $this->fetchJsonOrFail(env('LENDERS_JSON_URL'));
        return is_array($json['lenders'] ?? null) ? $json['lenders'] : [];
    }

    private function loadSaccos(): array
    {
        // Try local file first (with status data from cron)
        $localPath = resource_path('kenya_saccos_expanded_v3.json');
        if (file_exists($localPath)) {
            $localData = json_decode(file_get_contents($localPath), true);
            if (is_array($localData['saccos'] ?? null)) {
                return $localData['saccos'];
            }
        }

        // Fallback to remote URL
        $json = $this->fetchJsonOrFail(env('SACCOS_JSON_URL'));
        return is_array($json['saccos'] ?? null) ? $json['saccos'] : [];
    }

    private function loadCountyCentroids(): array
    {
        // Try local file first (with status data from cron)
        $localPath = resource_path('kenya_counties_centroids.json');
        if (file_exists($localPath)) {
            $localData = json_decode(file_get_contents($localPath), true);
            if (is_array($localData['counties'] ?? null)) {
                return $localData['counties'];
            }
        }

        // Fallback to remote URL
        $json = $this->fetchJsonOrFail(env('KENYA_COUNTIES_CENTROIDS_JSON_URL'));
        return is_array($json['counties'] ?? null) ? $json['counties'] : [];
    }

    private function loadSasraLive(): array
    {
        // Try local file first (with status data from cron)
        $localPath = resource_path('sacco_societies_kenya_2025_full.json');
        if (file_exists($localPath)) {
            $localData = json_decode(file_get_contents($localPath), true);
            if (is_array($localData)) {
                return $localData;
            }
        }

        // Fallback to remote URL
        $json = $this->fetchJsonOrFail(env('SACCO_SOCIETIES_KENYA_2025_FULL_JSON_JSON_URL'));
        return is_array($json) ? $json : [];
    }

    private function filterAndPaginate(array $lenders, Request $request): array
    {
        $query = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', 'All');
        $shariah = (string) $request->query('shariah', 'All');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('perPage', 10)));

        $filtered = array_values(array_filter($lenders, function ($l) use ($query, $type, $shariah) {
            $lType = (string) ($l['type'] ?? '');
            if ($type !== 'All' && $lType !== $type) return false;

            if ($shariah !== 'All') {
                $want = $shariah === 'Shariah';
                $is = (bool) ($l['shariah'] ?? false);
                if ($is !== $want) return false;
            }

            if ($query === '') return true;
            $hay = strtolower(trim(implode(' ', [
                (string) ($l['name'] ?? ''),
                (string) ($l['type'] ?? ''),
                (string) ($l['notes'] ?? ''),
                implode(' ', (array) ($l['tags'] ?? [])),
            ])));
            return str_contains($hay, strtolower($query));
        }));

        $total = count($filtered);
        $pages = (int) ceil($total / $perPage ?: 1);
        $start = ($page - 1) * $perPage;
        $items = array_slice($filtered, $start, $perPage);

        // Build type options
        $types = ['All'];
        foreach ($lenders as $l) {
            $t = (string) ($l['type'] ?? '');
            if ($t !== '' && !in_array($t, $types, true)) {
                $types[] = $t;
            }
        }

        return [
            'items' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'pages' => max(1, $pages),
                'types' => $types,
                'filters' => [
                    'q' => $query,
                    'type' => $type,
                    'shariah' => $shariah,
                ],
            ],
        ];
    }

    //Original API Methods
    public function pageBanks(Request $request)
    {
        try {
            $lenders = $this->loadLenders();
            $result = $this->filterAndPaginate($lenders, $request);
            return response()->json([
                'items' => $result['items'],
                'meta' => $result['meta'],
                'tab' => 'banks',
                'error' => null,
            ], 200);
        } catch (\Throwable $e) {
            $status = $this->extractStatus($e);
            return response()->json([
                'items' => [],
                'meta' => [
                    'total' => 0, 'page' => 1, 'perPage' => 10, 'pages' => 1,
                    'types' => ['All'],
                    'filters' => ['q' => '', 'type' => 'All', 'shariah' => 'All'],
                ],
                'tab' => 'banks',
                'error' => ['message' => $e->getMessage(), 'status' => $status],
            ], 500);
        }
    }

    public function apiBanks(Request $request)
    {
        try {
            $lenders = $this->loadLenders();
            $result = $this->filterAndPaginate($lenders, $request);
            return response()->json([
                'lenders' => $result['items'],
                'meta' => $result['meta'],
            ]);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);
            $status = $this->extractStatus($e);
            return response()->json(['error' => 'Something went wrong.'], $status);
        }
    }

    private function filterAndPaginateSaccos(array $saccos, Request $request): array
    {
        $query = trim((string) $request->query('q', ''));
        $county = (string) $request->query('county', 'All');
        $membership = (string) $request->query('membership', 'All');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('perPage', 10)));

        $filtered = array_values(array_filter($saccos, function ($s) use ($query, $county, $membership) {
            if ($county !== 'All' && (string)($s['county'] ?? '') !== $county) return false;
            if ($membership !== 'All' && (string)($s['membership_type'] ?? '') !== $membership) return false;
            if ($query === '') return true;
            $hay = strtolower(trim(implode(' ', [
                (string) ($s['name'] ?? ''),
                (string) ($s['county'] ?? ''),
                (string) ($s['membership_type'] ?? ''),
                (string) ($s['eligibility_notes'] ?? ''),
                implode(' ', (array) ($s['tags'] ?? [])),
            ])));
            return str_contains($hay, strtolower($query));
        }));

        $total = count($filtered);
        $pages = (int) ceil($total / $perPage ?: 1);
        $start = ($page - 1) * $perPage;
        $items = array_slice($filtered, $start, $perPage);

        // Build county and membership options
        $counties = ['All'];
        $memberships = ['All'];
        foreach ($saccos as $s) {
            $c = (string) ($s['county'] ?? '');
            $m = (string) ($s['membership_type'] ?? '');
            if ($c !== '' && !in_array($c, $counties, true)) $counties[] = $c;
            if ($m !== '' && !in_array($m, $memberships, true)) $memberships[] = $m;
        }

        return [
            'items' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'pages' => max(1, $pages),
                'counties' => $counties,
                'memberships' => $memberships,
                'filters' => [
                    'q' => $query,
                    'county' => $county,
                    'membership' => $membership,
                ],
            ],
        ];
    }

    public function pageSaccosCurated(Request $request)
    {
        try {
            $saccos = $this->loadSaccos();
            $result = $this->filterAndPaginateSaccos($saccos, $request);
            $centroids = $this->loadCountyCentroids();

            return response()->json([
                'items' => $result['items'],
                'meta' => $result['meta'],
                'tab' => 'saccos_curated',
                'centroids' => $centroids,
                'error' => null,
            ], 200);
        } catch (\Throwable $e) {
            $status = $this->extractStatus($e);
            return response()->json([
                'items' => [],
                'meta' => [
                    'total' => 0, 'page' => 1, 'perPage' => 10, 'pages' => 1,
                    'counties' => ['All'], 'memberships' => ['All'],
                    'filters' => ['q' => '', 'county' => 'All', 'membership' => 'All'],
                ],
                'tab' => 'saccos_curated',
                'centroids' => [],
                'error' => ['message' => $e->getMessage(), 'status' => $status],
            ], 500);
        }
    }

    public function apiSaccos(Request $request)
    {
        try {
            $saccos = $this->loadSaccos();
            $result = $this->filterAndPaginateSaccos($saccos, $request);
            return response()->json([
                'saccos' => $result['items'],
                'meta' => $result['meta'],
            ]);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);
            $status = $this->extractStatus($e);
            return response()->json(['error' => 'Something went wrong.'], $status);
        }
    }

    private function filterAndPaginateLive(array $rows, Request $request): array
    {
        $query = trim((string) $request->query('q', ''));
        $county = (string) $request->query('county', 'All');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('perPage', 10)));

        $filtered = array_values(array_filter($rows, function ($r) use ($query, $county) {
            $rCounty = (string) ($r['County'] ?? ($r['county'] ?? ''));
            if ($county !== 'All' && $rCounty !== $county) return false;
            if ($query === '') return true;
            $hay = strtolower(trim(implode(' ', [
                (string) ($r['name'] ?? ''),
                (string) ($r['postal'] ?? ''),
                (string) ($r['physical'] ?? ''),
                (string) ($r['County'] ?? ($r['county'] ?? '')),
                (string) ($r['schedule'] ?? ''),
            ])));
            return str_contains($hay, strtolower($query));
        }));

        $total = count($filtered);
        $pages = (int) ceil($total / $perPage ?: 1);
        $start = ($page - 1) * $perPage;
        $items = array_slice($filtered, $start, $perPage);

        $counties = ['All'];
        foreach ($rows as $r) {
            $c = (string) ($r['County'] ?? ($r['county'] ?? ''));
            if ($c !== '' && !in_array($c, $counties, true)) $counties[] = $c;
        }

        return [
            'items' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'pages' => max(1, $pages),
                'counties' => $counties,
                'filters' => [
                    'q' => $query,
                    'county' => $county,
                ],
            ],
        ];
    }

    public function pageSaccosLive(Request $request)
    {
        try {
            $rows = $this->loadSasraLive();
            $result = $this->filterAndPaginateLive($rows, $request);
            $centroids = $this->loadCountyCentroids();

            return response()->json([
                'items' => $result['items'],
                'meta' => $result['meta'],
                'tab' => 'saccos_live',
                'centroids' => $centroids,
                'error' => null,
            ], 200);
        } catch (\Throwable $e) {
            $status = $this->extractStatus($e);
            return response()->json([
                'items' => [],
                'meta' => [
                    'total' => 0, 'page' => 1, 'perPage' => 10, 'pages' => 1,
                    'counties' => ['All'],
                    'filters' => ['q' => '', 'county' => 'All'],
                ],
                'tab' => 'saccos_live',
                'centroids' => [],
                'error' => ['message' => $e->getMessage(), 'status' => $status],
            ], 500);
        }
    }

    public function apiSaccosLive(Request $request)
    {
        try {
            $rows = $this->loadSasraLive();
            $result = $this->filterAndPaginateLive($rows, $request);
            return response()->json([
                'rows' => $result['items'],
                'meta' => $result['meta'],
            ]);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);
            $status = $this->extractStatus($e);
            return response()->json(['error' => 'Something went wrong.'], $status);
        }
    }

    private function extractStatus(\Throwable $e): int
    {
        $msg = $e->getMessage();
        if (str_starts_with($msg, 'REMOTE_ERROR:')) {
            $code = (int) substr($msg, strlen('REMOTE_ERROR:'));
            return $code > 0 ? $code : 502;
        }
        if ($msg === 'DATA_URL_NOT_CONFIGURED') return 500;
        if ($msg === 'INVALID_JSON') return 502;
        return 500;
    }
}
