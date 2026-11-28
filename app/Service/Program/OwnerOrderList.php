<?php

namespace App\Service\Program;

use App\Http\Resources\Program\Monitoring\MESiteVisitFileResource;
use App\Models\Auth\User;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\Programs\Monitoring\MESiteVisitFile;
use App\Models\Programs\Program;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\ReviewerOrder;
use App\Support\ApiContract;
use App\Support\ListQuery;
use App\Support\ListShapes;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The program owner's Business Coach orders (round reviews and site visits) as one server list: the Orders page and the
 * Site visits page. Search, filters, sort, page and export run on the database (see App\Support\ListQuery); the stats
 * describe every order in scope (the chosen program and order type), whatever the search or filters.
 *
 * Who sees what is the same as the two per-program lists this replaces on those pages:
 *   round review orders  programs the person created (ReviewerOrderController::roundOrders)
 *   site visit orders    programs ProgramAccess::owns() lets them run (SiteVisitController::ownerOrders)
 *
 * Scope params: program=<id> (403 when it is not theirs), order_type=round_review|site_visit, tab=pending|delivered|completed
 * (the tab narrows rows and filter counts, not the stats). with_visit=1 adds each site visit's uploaded files (visit_files).
 * Filters: id, tab (pending|delivered|completed), work, payment, type, round, coach, program, fee (min~max), overdue (yes|no),
 * deadline, created; all but id / tab / program are described in `filter_schema`. With order_type=site_visit (the Site
 * visits page) type and round are not described, and program, business, location, visit (day), located and files are.
 * Sorts: id, deadline, fee, work, created, program (program title, then round / site visit checkpoint), coach (Business Coach name).
 */
class OwnerOrderList
{
    public const TAB_STATUSES = [
        'pending' => ['assigned', 'pending', 'accepted', 'in_progress'],
        'delivered' => ['delivered', 'modification_requested'],
        'completed' => ['approved', 'rejected'],
    ];

    /** Work statuses in the order the panel lists them, with the label each one shows (app i18n keys). */
    private const WORK_LABELS = [
        'assigned' => 'app:assigned', 'pending' => 'app:pending2', 'accepted' => 'app:accepted', 'in_progress' => 'app:inProgress2',
        'delivered' => 'app:delivered', 'modification_requested' => 'app:reviseRequested', 'approved' => 'app:approved', 'rejected' => 'app:rejected',
    ];
    private const PAYMENT_LABELS = [
        'unpaid' => 'app:notPaidYet', 'pending' => 'app:paymentStarted', 'processing' => 'app:processing', 'leg1_processing' => 'app:sending',
        'paid' => 'app:paid', 'failed' => 'app:failedRetry', 'none' => 'common:ordersPage.noPaymentNeeded',
    ];
    /** Quick ranges for a date that can lie on either side of today (a deadline, a visit day). */
    private const PAST_AND_FUTURE = ['today', 'next7Days', 'next30Days', 'yesterday', 'last7Days', 'last30Days', 'thisMonth', 'lastMonth', 'thisYear'];

    /** Work that is still owed by the Business Coach (an order past its deadline in one of these is overdue). */
    private const OPEN_WORK = ['assigned', 'pending', 'accepted', 'in_progress', 'modification_requested'];

    /** The payment a person reads: none (nothing to pay), paid, or the server's own payment status. */
    private const PAYMENT_KEY = "CASE WHEN COALESCE(reviewer_orders.fee_amount, 0) <= 0 THEN 'none' WHEN reviewer_orders.payment_status = 'completed' THEN 'paid' ELSE COALESCE(reviewer_orders.payment_status, 'unpaid') END";

    public function __construct(private User $user)
    {
    }

    public function respond(Request $request): JsonResponse
    {
        [$roundPrograms, $sitePrograms] = $this->programIds();

        $programId = $request->query('program');
        if ($programId !== null && $programId !== '') {
            $programId = (int) $programId;
            if (! in_array($programId, $roundPrograms, true) && ! in_array($programId, $sitePrograms, true)) {
                return response()->json(['success' => false, 'error' => 'Unauthorized', 'message' => ProgramAccess::DENIED], 403);
            }
            $roundPrograms = array_values(array_intersect($roundPrograms, [$programId]));
            $sitePrograms = array_values(array_intersect($sitePrograms, [$programId]));
        }

        $type = in_array($request->query('order_type'), ['round_review', 'site_visit'], true) ? $request->query('order_type') : null;
        $scope = fn () => $this->scope($roundPrograms, $sitePrograms, $type);

        $searched = $scope()->with([
            'program:id,program_title,program_type',
            'reviewer',
            'round:id,round_number,round_name,review_period_end,close_date',
            'siteVisit.checkpoint.application:id,startup_name',
            'applicationAssignments.application.business:id,name',
        ]);
        $this->applySearch($searched, $request->query('search'));
        // The page's stage tab narrows the rows and the filter counts (the stats keep the whole scope)
        if (is_string($tab = $request->query('tab')) && isset(self::TAB_STATUSES[$tab])) {
            $searched->whereIn('reviewer_orders.work_status', self::TAB_STATUSES[$tab]);
        }

        // Sorts on other tables (subqueries, so a row is never repeated): the program and round column, the Business Coach
        ListShapes::extraSort($searched, $request, [
            'program' => fn (Builder $q, string $dir) => $q
                ->orderBy(Program::select('program_title')->whereColumn('programs.id', 'reviewer_orders.program_id')->limit(1), $dir)
                ->orderBy(ProgramRound::select('round_number')->whereColumn('program_rounds.id', 'reviewer_orders.round_id')->limit(1), $dir)
                ->orderBy(MESiteVisit::query()->join('m_e_checkpoints', 'm_e_checkpoints.id', '=', 'm_e_site_visits.checkpoint_id')
                    ->select('m_e_checkpoints.checkpoint_name')->whereColumn('m_e_site_visits.id', 'reviewer_orders.site_visit_id')->limit(1), $dir),
            'coach' => fn (Builder $q, string $dir) => $q
                ->orderBy(User::select('first_name')->whereColumn('users.id', 'reviewer_orders.reviewer_id')->limit(1), $dir)
                ->orderBy(User::select('last_name')->whereColumn('users.id', 'reviewer_orders.reviewer_id')->limit(1), $dir),
        ]);

        $list = ListQuery::for($searched, $request)
            ->filter('id', 'id')
            ->filter('tab', function (Builder $q, array $values) {
                $statuses = collect($values)->flatMap(fn ($v) => self::TAB_STATUSES[$v] ?? [])->all();
                if ($statuses) {
                    $q->whereIn('reviewer_orders.work_status', $statuses);
                }
            })
            // Described filters: the Orders page builds its whole panel, chips and URL state from `filter_schema`
            ->options('work', 'work_status', label: 'app:workStatus', values: array_keys(self::WORK_LABELS), optionLabels: self::WORK_LABELS)
            ->options('payment', DB::raw(self::PAYMENT_KEY), label: 'app:payment', values: array_keys(self::PAYMENT_LABELS), optionLabels: self::PAYMENT_LABELS);
        $siteVisits = $type === 'site_visit';
        if ($siteVisits) {
            // no order types or rounds to pick on the Site visits page (still applied when sent)
            $list->filter('type', 'order_type')->filter('round', 'round_id');
            $this->siteVisitFilters($list);
        } else {
            $list->options('type', 'order_type', label: 'app:listFilterOrderType', optionLabels: ['round_review' => 'app:roundReview', 'site_visit' => 'app:siteVisit'])
                ->entity('round', 'round_id', fn (array $ids) => self::roundNames($ids), label: 'app:round2');
        }
        $list->entity('coach', 'reviewer_id', fn (array $ids) => self::coachNames($ids), label: 'app:reviewer', fallback: 'Business Coach')
            ->numberRange('fee', 'fee_amount', label: 'app:fee')
            ->boolean('overdue', fn (Builder $q, bool $yes) => $this->whereOverdue($q, $yes), label: 'app:overdue')
            ->dateRange('deadline', 'deadline', label: 'app:deadline2', presets: self::PAST_AND_FUTURE)
            ->dateRange('created', 'created_at', label: 'app:created')
            ->sortable(['id' => 'id', 'deadline' => 'deadline', 'fee' => 'fee_amount', 'work' => 'work_status', 'created' => 'created_at'], default: '-id');
        if ($siteVisits) {
            $this->siteVisitChecks($list);
        } else {
            $list->filter('program', 'program_id'); // the Orders page picks the program above the table
        }

        [$rows, $page] = $list->fetch();

        $items = $this->shape($rows);
        // with_visit=1 (the site visit page opening one order): the officer's uploaded files too, as short-lived links
        if ($request->boolean('with_visit')) {
            $items = $this->withVisitFiles($items, $request);
        }

        $data = [
            'items' => $items,
            'facets' => $this->facets($list),
            'filter_schema' => $list->filterSchema(),
            'stats' => $this->stats($scope()),
            'export_truncated' => $list->wantsExport() ? $list->exportTruncated() : false,
        ];
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }

        return response()->json(['success' => true, 'message' => 'Orders retrieved', 'data' => $data]);
    }

    /** [program ids whose round review orders they see, program ids whose site visit orders they see] */
    private function programIds(): array
    {
        // The program team sees both kinds (round reviews used to be only the creator's), like the site visit orders
        $site = Program::ofOrganization($this->user)->get(['id', 'user_id', 'organization_id']) // ISSUE-38
            ->filter(fn (Program $p) => ProgramAccess::owns($p))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        return [$site, $site];
    }

    private function scope(array $roundPrograms, array $sitePrograms, ?string $type): Builder
    {
        return ReviewerOrder::query()->where(function (Builder $q) use ($roundPrograms, $sitePrograms, $type) {
            if ($type !== 'site_visit') {
                $q->orWhere(fn (Builder $r) => $r->where('reviewer_orders.order_type', 'round_review')->whereIn('reviewer_orders.program_id', $roundPrograms ?: [0]));
            }
            if ($type !== 'round_review') {
                $q->orWhere(fn (Builder $s) => $s->where('reviewer_orders.order_type', 'site_visit')->whereNotNull('reviewer_orders.site_visit_id')->whereIn('reviewer_orders.program_id', $sitePrograms ?: [0]));
            }
        });
    }

    /** Every word must appear in the order number, the Business Coach, the program, the round or the site visit. */
    private function applySearch(Builder $q, $search): void
    {
        foreach (ListQuery::words(is_string($search) ? $search : null) as $word) {
            $like = '%'.ListQuery::escapeLike($word).'%';
            $q->where(function (Builder $any) use ($like) {
                $any->whereRaw("CAST(reviewer_orders.id AS CHAR) like ? escape '!'", [$like])
                    ->orWhereHas('reviewer', fn (Builder $u) => $u->where(fn (Builder $w) => $w
                        ->whereRaw("users.first_name like ? escape '!'", [$like])
                        ->orWhereRaw("users.last_name like ? escape '!'", [$like])
                        ->orWhereRaw("users.email like ? escape '!'", [$like])))
                    ->orWhereHas('program', fn (Builder $p) => $p->whereRaw("programs.program_title like ? escape '!'", [$like]))
                    ->orWhereHas('round', fn (Builder $r) => $r->whereRaw("program_rounds.round_name like ? escape '!'", [$like]))
                    ->orWhereHas('siteVisit', fn (Builder $v) => $v->where(fn (Builder $w) => $w
                        ->whereRaw("m_e_site_visits.location like ? escape '!'", [$like])
                        ->orWhereHas('checkpoint', fn (Builder $c) => $c->where(fn (Builder $cw) => $cw
                            ->whereRaw("m_e_checkpoints.checkpoint_name like ? escape '!'", [$like])
                            ->orWhereHas('application', fn (Builder $a) => $a->whereRaw("program_applications.startup_name like ? escape '!'", [$like]))))));
            });
        }
    }

    /**
     * The Site visits page (order_type=site_visit) has no rounds or order types to pick; it filters by the program, the
     * business visited, where, the visit day, and whether the Business Coach confirmed the place and uploaded files.
     * The order type and round filters still apply when sent, they are only not offered.
     */
    private function siteVisitFilters(ListQuery $list): void
    {
        $visit = fn (string $column) => fn (Builder $q, array $values) => $q->whereHas('siteVisit', fn (Builder $v) => $v->whereIn('m_e_site_visits.'.$column, $values));
        $countVisit = fn (string $column) => fn (Builder $q) => $q->join('m_e_site_visits as sv', 'sv.id', '=', 'reviewer_orders.site_visit_id')
            ->whereNotNull('sv.'.$column)->where('sv.'.$column, '!=', '')
            ->select('sv.'.$column.' as value', DB::raw('count(*) as count'))
            ->groupBy('sv.'.$column)->orderByDesc('count')->limit(100)->toBase()->get()
            ->map(fn ($row) => ['value' => $row->value, 'count' => (int) $row->count])->all();

        $list->entity('program', 'program_id', fn (array $ids) => Program::whereIn('id', $ids)->pluck('program_title', 'id')->all(), label: 'app:program')
            ->options('business', $visit('app_id'), label: 'app:listFilterBusiness', countBy: $countVisit('app_id'), searchable: true,
                optionLabels: fn (array $ids) => \App\Models\Programs\ProgramApplication::whereIn('id', $ids)->pluck('startup_name', 'id')->all())
            ->options('location', $visit('location'), label: 'app:location', countBy: $countVisit('location'))
            ->dateRange('visit', fn (Builder $q, ?string $from, ?string $to) => $q->whereHas('siteVisit', function (Builder $v) use ($from, $to) {
                if ($from) {
                    $v->whereDate('m_e_site_visits.start_date', '>=', $from);
                }
                if ($to) {
                    $v->whereDate('m_e_site_visits.start_date', '<=', $to);
                }
            }), label: 'app:visitDate', presets: self::PAST_AND_FUTURE);
    }

    /** Site visits: did the Business Coach confirm the place, and upload files. */
    private function siteVisitChecks(ListQuery $list): void
    {
        $list->boolean('located', fn (Builder $q, bool $yes) => $yes
                ? $q->whereHas('siteVisit', fn (Builder $v) => $v->where('m_e_site_visits.confirm_location', true))
                : $q->whereDoesntHave('siteVisit', fn (Builder $v) => $v->where('m_e_site_visits.confirm_location', true)), label: 'app:listFilterLocationConfirmed')
            ->boolean('files', function (Builder $q, bool $yes) {
                $files = fn ($f) => $f->select(DB::raw(1))->from('m_e_site_visit_files')->whereColumn('m_e_site_visit_files.site_visit_id', 'reviewer_orders.site_visit_id');
                $yes ? $q->whereExists($files) : $q->whereNotExists($files);
            }, label: 'app:listFilterHasVisitFiles');
    }

    /** Overdue: the deadline has passed and the work is still open (not delivered, approved or rejected). */
    private function whereOverdue(Builder $q, bool $yes): void
    {
        $now = now();
        $yes
            ? $q->whereNotNull('reviewer_orders.deadline')->where('reviewer_orders.deadline', '<', $now)->whereIn('reviewer_orders.work_status', self::OPEN_WORK)
            : $q->where(fn (Builder $w) => $w->whereNull('reviewer_orders.deadline')->orWhere('reviewer_orders.deadline', '>=', $now)
                ->orWhereNull('reviewer_orders.work_status')->orWhereNotIn('reviewer_orders.work_status', self::OPEN_WORK));
    }

    /** @return array<int, string> Business Coach id => name (email when the profile has no name) */
    private static function coachNames(array $ids): array
    {
        return User::whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'email'])->mapWithKeys(fn ($u) => [$u->id => self::person($u) ?: $u->email])->all();
    }

    /** @return array<int, string> round id => name */
    private static function roundNames(array $ids): array
    {
        return ProgramRound::whereIn('id', $ids)->get(['id', 'round_number', 'round_name'])->mapWithKeys(fn ($r) => [$r->id => $r->round_name ?: 'Round '.$r->round_number])->all();
    }

    private static function person($user): string
    {
        return $user ? trim(implode(' ', array_filter([$user->first_name, $user->last_name], fn ($v) => $v && $v !== 'null'))) : '';
    }

    /** One row per order, in the shape the two old lists sent plus the program and round names the table reads. */
    private function shape(Collection $orders): Collection
    {
        // Each Business Coach's scores for their assigned applications, in one query for the whole page
        $roundOrders = $orders->where('order_type', 'round_review')->filter(fn ($o) => $o->applicationAssignments->isNotEmpty());
        $scores = $roundOrders->isEmpty() ? collect() : ApplicationScore::query()
            ->whereIn('round_id', $roundOrders->pluck('round_id')->unique()->filter())
            ->whereIn('reviewer_id', $roundOrders->pluck('reviewer_id')->unique()->filter())
            ->whereIn('application_id', $roundOrders->flatMap(fn ($o) => $o->applicationAssignments->pluck('application_id'))->unique())
            ->get(['round_id', 'reviewer_id', 'application_id', 'total_score'])
            ->keyBy(fn ($s) => $s->round_id.'-'.$s->reviewer_id.'-'.$s->application_id);

        return $orders->map(function (ReviewerOrder $order) use ($scores) {
            $completed = $order->applicationAssignments->where('status', 'completed')->count();
            $minimum = $order->minimumPaidApplications();
            $checkpoint = $order->siteVisit?->checkpoint;
            $isSiteVisit = $order->order_type === 'site_visit';

            return [
                ...$order->toArray(),
                'fee_amount' => $order->fee_amount,
                'applications' => $order->applicationAssignments->sortBy('assigned_at')->map(fn ($a) => [
                    'application_id' => $a->application_id,
                    'business_name' => $a->application?->business?->name ?? $a->application?->startup_name,
                    'status' => $a->status,
                    'assigned_at' => $a->assigned_at,
                    'started_at' => $a->started_at,
                    'completed_at' => $a->completed_at,
                    'total_score' => $scores[$order->round_id.'-'.$order->reviewer_id.'-'.$a->application_id]->total_score ?? null,
                ])->values(),
                'completed_application_count' => $completed,
                'minimum_apps_to_get_paid' => $minimum ?: null,
                'paid_threshold_met' => $minimum ? $completed >= $minimum : false,
                'work_status' => \App\Support\ApiContract::statusMeta('reviewer_order', $order->work_status),
                'payment_status' => \App\Support\ApiContract::statusMeta('reviewer_payment', $order->payment_status),
                'payment_key' => $this->paymentKey($order),
                'program_title' => $order->program?->program_title,
                'program_type' => $order->program?->program_type,
                'round_name' => $isSiteVisit
                    ? ($checkpoint?->checkpoint_name ?: 'Site visit '.$order->site_visit_id)
                    : ($order->round?->round_name ?: 'Round '.($order->round?->round_number ?? '')),
                'business_name' => $checkpoint?->application?->startup_name,
                'application_id' => $checkpoint?->app_id,
                'reviewer_name' => self::person($order->reviewer),
                'location' => $order->siteVisit?->location,
            ];
        })->values();
    }

    /** Adds `visit_files` (the site visit's uploaded files, links valid for a while) to each site visit row, in one query. */
    private function withVisitFiles(Collection $items, Request $request): Collection
    {
        $visitIds = $items->pluck('site_visit_id')->filter()->unique()->values();
        $files = $visitIds->isEmpty() ? collect() : MESiteVisitFile::whereIn('site_visit_id', $visitIds)->orderBy('id')->get()->groupBy('site_visit_id');

        return $items->map(fn (array $row) => $row + [
            'visit_files' => $row['site_visit_id']
                ? MESiteVisitFileResource::collection($files->get($row['site_visit_id'], collect()))->resolve($request)
                : [],
        ]);
    }

    private function paymentKey(ReviewerOrder $order): string
    {
        if ((float) ($order->fee_amount ?? 0) <= 0) {
            return 'none';
        }

        return $order->payment_status === 'completed' ? 'paid' : ($order->payment_status ?: 'unpaid');
    }

    /** Option counts per filter (each with the search and the other filters applied), with names for ids. */
    private function facets(ListQuery $list): array
    {
        $facets = $list->facets(['work', 'type', 'payment']);

        $named = function (string $key, string $column, Closure $names) use ($list) {
            $counts = $list->query($key, sorted: false)->reorder()->whereNotNull('reviewer_orders.'.$column)
                ->select('reviewer_orders.'.$column.' as value', DB::raw('count(*) as count'))
                ->groupBy('reviewer_orders.'.$column)->orderByDesc('count')->limit(100)->toBase()->get();
            $labels = $names($counts->pluck('value')->all());

            return $counts->map(fn ($r) => ['value' => $r->value, 'count' => (int) $r->count, 'label' => $labels[$r->value] ?? null])->all();
        };

        $facets['coach'] = $named('coach', 'reviewer_id', fn ($ids) => self::coachNames($ids));
        $facets['round'] = $named('round', 'round_id', fn ($ids) => self::roundNames($ids));
        $facets['program'] = $named('program', 'program_id', fn ($ids) => Program::whereIn('id', $ids)->pluck('program_title', 'id')->all());

        return $facets;
    }

    /** The cards and tab counts: every order in scope, whatever the search or filters. */
    private function stats(Builder $scope): array
    {
        $byStatus = (clone $scope)->select('work_status', DB::raw('count(*) as count'))->groupBy('work_status')->toBase()->pluck('count', 'work_status')
            ->map(fn ($n) => (int) $n);
        $sum = fn (array $statuses) => (int) collect($statuses)->sum(fn ($s) => $byStatus[$s] ?? 0);

        // Fees: orders that pay something and were not rejected; kept per currency, never added across currencies
        $fees = (clone $scope)->where('fee_amount', '>', 0)->where(fn (Builder $q) => $q->whereNull('work_status')->orWhere('work_status', '!=', 'rejected'))
            ->select(DB::raw("COALESCE(currency, '') as cur"), DB::raw('SUM(fee_amount) as total'), DB::raw("SUM(CASE WHEN payment_status = 'completed' THEN fee_amount ELSE 0 END) as paid"))
            ->groupBy(DB::raw("COALESCE(currency, '')"))->toBase()->get();
        $currencies = $fees->pluck('cur')->filter()->values();
        $total = round((float) $fees->sum('total'), 2);
        $paid = round((float) $fees->sum('paid'), 2);

        return [
            'total' => (int) $byStatus->sum(),
            'tabs' => [
                'all' => (int) $byStatus->sum(),
                'pending' => $sum(self::TAB_STATUSES['pending']),
                'delivered' => $sum(self::TAB_STATUSES['delivered']),
                'completed' => $sum(self::TAB_STATUSES['completed']),
            ],
            'by_status' => $byStatus->all(),
            'coaches' => (clone $scope)->distinct()->count('reviewer_id'),
            'programs' => (clone $scope)->distinct()->count('program_id'),
            'fees' => [
                'total' => $total,
                'paid' => $paid,
                'pending' => round(max($total - $paid, 0), 2),
                // one currency when every fee is in it; otherwise the amounts are only indicative and shown without one
                'currency' => $currencies->unique()->count() === 1 ? $currencies->first() : null,
                'by_currency' => $fees->filter(fn ($r) => $r->cur !== '')->mapWithKeys(fn ($r) => [$r->cur => ['total' => round((float) $r->total, 2), 'paid' => round((float) $r->paid, 2)]])->all(),
            ],
        ];
    }
}
