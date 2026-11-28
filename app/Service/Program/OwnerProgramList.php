<?php

namespace App\Service\Program;

use App\Models\Programs\ProgramApplication;
use App\Support\ApiContract;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The owner's "My Programs" table on the server: search, filters (status, type, visibility, match score, currency, funding,
 * applications, rounds, opens, deadline, created, has applications, KYC required, wallet funded; all in `filter_schema`), sort, page and
 * export over the programs the owner (or their team) may see, the option counts for the Filter popover and the totals
 * for the summary cards over ALL of those programs (the cards do not follow the search).
 *
 * Without any list parameter the endpoint answers as it always did (every program, newest first), so the many screens
 * that read the owner's programs as a picker keep working.
 */
class OwnerProgramList
{
    private const LIVE = ['open', 'published'];
    /** Quick ranges for a date that can lie on either side of today (opening day, deadline) */
    private const PAST_AND_FUTURE = ['today', 'next7Days', 'next30Days', 'yesterday', 'last7Days', 'last30Days', 'thisMonth', 'lastMonth', 'thisYear'];

    private ListQuery $list;

    private function __construct(private Builder $base, private Request $request)
    {
        $query = clone $base;

        // The application count is a computed column, so it is ordered here rather than as a ListQuery sort key
        // (an unknown key falls back to the default sort, which then only breaks ties)
        $sort = (string) $request->query('sort', '');
        if (ltrim($sort, '-') === 'applications') {
            $query->orderBy('applications_count', str_starts_with($sort, '-') ? 'desc' : 'asc');
        }

        $twoWayCount = fn (string $yes, string $no, callable $where) => function (Builder $q) use ($yes, $no, $where) {
            $total = (clone $q)->toBase()->getCountForPagination();
            $yesCount = (clone $q)->where(fn ($s) => $where($s))->toBase()->getCountForPagination();

            return [['value' => $yes, 'count' => $yesCount], ['value' => $no, 'count' => $total - $yesCount]];
        };
        $isSingle = fn (Builder $s) => $s->where('programs.program_type', 'single_round');
        $isVisible = fn (Builder $s) => $s->where('programs.visible', true);
        $usesMatch = fn (Builder $s) => $s->where('programs.use_match_score', true);
        $applications = '(select count(*) from program_applications where program_applications.program_id = programs.id)';

        // Described filters in `filter_schema` (the old keys and values are unchanged; the page may build its own panel)
        $this->list = ListQuery::for($query, $request)
            ->search(['program_title', 'program_type'])
            ->filter('id', 'id')
            ->options('status', 'status', label: 'app:status')
            ->options('type', fn (Builder $q, array $values) => self::twoWay($q, $values, 'single_round', 'multi_round', $isSingle), label: 'app:type',
                values: ['single_round', 'multi_round'], optionLabels: ['single_round' => 'app:singleRound', 'multi_round' => 'app:multiRound'],
                countBy: $twoWayCount('single_round', 'multi_round', $isSingle))
            ->options('visibility', fn (Builder $q, array $values) => self::twoWay($q, $values, 'visible', 'hidden', $isVisible), label: 'app:visibility',
                values: ['visible', 'hidden'], optionLabels: ['visible' => 'app:visibleToApplicants', 'hidden' => 'app:hiddenFromApplicants'],
                countBy: $twoWayCount('visible', 'hidden', $isVisible))
            ->options('match', fn (Builder $q, array $values) => self::twoWay($q, $values, 'on', 'off', $usesMatch), label: 'app:matchScore',
                values: ['on', 'off'], optionLabels: ['on' => 'app:matchScoreOn', 'off' => 'app:matchScoreOff'],
                countBy: $twoWayCount('on', 'off', $usesMatch))
            ->options('currency', 'currency', label: 'app:currency')
            ->numberRange('amount', 'total_program_amount', label: 'app:funding')
            ->numberRange('applications', DB::raw($applications), label: 'app:applicationsColumn')
            ->numberRange('rounds', 'total_rounds', label: 'app:rounds')
            ->dateRange('opens', 'application_start_date', label: 'app:opens', presets: self::PAST_AND_FUTURE)
            ->dateRange('deadline', 'application_deadline', label: 'app:deadlineColumn', presets: self::PAST_AND_FUTURE)
            ->dateRange('created', 'created_at', label: 'app:created')
            ->boolean('has_applications', fn (Builder $q, bool $yes) => $q->whereRaw($applications.($yes ? ' > 0' : ' = 0')), label: 'app:listFilterHasApplications')
            ->boolean('kyc_required', 'require_kyc_verification_to_apply', label: 'app:listFilterKycRequired')
            ->boolean('funded', fn (Builder $q, bool $yes) => $yes
                ? $q->whereHas('wallet', fn (Builder $w) => $w->where('balance', '>', 0))
                : $q->whereDoesntHave('wallet', fn (Builder $w) => $w->where('balance', '>', 0)), label: 'app:listFilterWalletFunded')
            ->sortable([
                'title' => 'program_title',
                'amount' => 'total_program_amount',
                'deadline' => 'application_deadline',
                'status' => 'status',
                'created' => 'created_at',
            ], default: '-created');
    }

    public static function for(Builder $base, Request $request): static
    {
        return new static($base, $request);
    }

    /** True when the request asks for the paged / searchable form. */
    public function listMode(): bool
    {
        $r = $this->request;

        return ApiContract::wantsPagination($r) || $r->boolean('export')
            || $r->filled('search') || $r->filled('filters') || $r->filled('sort');
    }

    /** @return array{0: Collection, 1: \Illuminate\Contracts\Pagination\LengthAwarePaginator|null} */
    public function fetch(): array
    {
        return $this->list->fetch();
    }

    public function respond(string $message, Collection $programs, $page): JsonResponse
    {
        $items = $programs->values();
        $data = [
            'items' => $items,
            'facets' => $this->facets(),
            'filter_schema' => $this->list->filterSchema(),
            'stats' => $this->stats(),
            'export_truncated' => $this->list->wantsExport() ? $this->list->exportTruncated() : false,
        ];
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }

        return response()->json(['success' => true, 'message' => $message, 'data' => $data, 'programs' => $items]);
    }

    /** A yes / no filter: only one of the two picked narrows the list (both or neither show everything). */
    private static function twoWay(Builder $q, array $values, string $yes, string $no, callable $where): void
    {
        $wantsYes = in_array($yes, $values, true);
        $wantsNo = in_array($no, $values, true);
        if ($wantsYes && ! $wantsNo) {
            $where($q);
        } elseif ($wantsNo && ! $wantsYes) {
            $q->whereNot(fn (Builder $n) => $where($n));
        }
    }

    private function facets(): array
    {
        $facets = $this->list->facets(['status']);

        $count = function (string $key, string $yes, string $no, callable $where) {
            $q = fn () => $this->list->query($key, sorted: false)->reorder()->toBase();
            $total = $q()->count();
            $yesCount = $q()->where(fn ($s) => $where($s))->count();

            return array_values(array_filter([
                ['value' => $yes, 'count' => $yesCount],
                ['value' => $no, 'count' => $total - $yesCount],
            ], fn ($o) => $o['count'] > 0));
        };

        $facets['type'] = $count('type', 'single_round', 'multi_round', fn ($s) => $s->where('program_type', 'single_round'));
        $facets['visibility'] = $count('visibility', 'visible', 'hidden', fn ($s) => $s->where('visible', true));
        $facets['match'] = $count('match', 'on', 'off', fn ($s) => $s->where('use_match_score', true));

        return $facets;
    }

    /** Totals over every program of the owner, whatever the search or filters. */
    private function stats(): array
    {
        $all = fn () => (clone $this->base)->reorder()->toBase();

        $total = $all()->count();
        $busiest = (clone $this->base)->reorder()->setEagerLoads([])
            ->orderByDesc('applications_count')->orderByDesc('programs.id')
            ->first();
        $currencies = $all()->whereNotNull('currency')->where('currency', '!=', '')->distinct()->pluck('currency')->values();
        $ids = (clone $this->base)->reorder()->toBase()->select('programs.id');

        return [
            'total' => $total,
            'active' => $all()->whereIn('status', self::LIVE)->count(),
            'visible' => $all()->where('visible', true)->count(),
            'match_on' => $all()->where('use_match_score', true)->count(),
            'applications' => ProgramApplication::whereIn('program_id', $ids)->count(),
            'busiest' => $busiest && (int) $busiest->applications_count > 0
                ? ['id' => $busiest->id, 'title' => $busiest->program_title, 'applications' => (int) $busiest->applications_count]
                : null,
            'funding' => round((float) $all()->sum('total_program_amount'), 2),
            'largest' => round((float) ($all()->max('total_program_amount') ?? 0), 2),
            'currencies' => $currencies,
        ];
    }
}
