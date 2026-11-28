<?php

namespace App\Service\Program;

use App\Http\Resources\Program\Monitoring\MESiteVisitResource;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Models\Programs\Rounds\RoundReviewer;
use App\Models\ReviewerOrder;
use App\Support\ApiContract;
use App\Support\CollectionList;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Business Coach's tables (review requests, accepted rounds, orders, site visits, the applications of a round)
 * and the service provider's program work, answered on the server: a page of rows with search, filters, sort and
 * export, the option counts for the filter popover and the totals for the cards over the WHOLE list.
 *
 * Every endpoint keeps its old full answer when the request has none of the list parameters (see wanted()).
 */
class ReviewerLists
{
    /** The rounds' application states the old assigned-rounds list counted. */
    public const ROUND_APPLICATION_STATES = ['pending', 'assigned', 'submitted', 'under_review', 'scored'];

    /** Site visit stages, in the order that needs attention first (see siteVisitStage on the frontend). */
    public const STAGES = ['pending', 'active', 'completed', 'declined', 'cancelled'];

    /** Quick ranges for dates that can be ahead or behind (deadlines, round closes, visit dates). */
    private const PAST_AND_FUTURE = ['today', 'next7Days', 'next30Days', 'yesterday', 'last7Days', 'last30Days', 'thisMonth', 'lastMonth', 'thisYear'];

    // Option labels the filter panel shows (app i18n keys)
    private const REQUEST_LABELS = ['pending' => 'app:pending2', 'accepted' => 'app:accepted', 'declined' => 'app:declined'];
    private const ROUND_LABELS = ['draft' => 'app:draft', 'published' => 'app:published', 'closed' => 'app:closed', 'in_review' => 'app:inReview', 'finalized' => 'app:finalized'];
    private const FEE_TYPE_LABELS = ['per_application' => 'app:perApplication', 'flat' => 'app:flatForTheRound', 'none' => 'app:noFee'];
    private const STAGE_LABELS = ['pending' => 'app:pending2', 'active' => 'app:active', 'completed' => 'app:completed', 'declined' => 'app:declined', 'cancelled' => 'app:cancelled'];
    private const WORK_KIND_LABELS = ['review_request' => 'app:roundReview', 'inspection' => 'app:siteVisit', 'audit' => 'app:listFilterMprvAudit', 'supplier' => 'app:supplier'];
    private const WORK_LABELS = [
        'assigned' => 'app:assigned', 'pending' => 'app:pending2', 'accepted' => 'app:accepted', 'in_progress' => 'app:inProgress2',
        'delivered' => 'app:delivered', 'modification_requested' => 'app:reviseRequested', 'approved' => 'app:approved', 'rejected' => 'app:rejected',
    ];
    private const PAYMENT_LABELS = [
        'none' => 'common:ordersPage.noPaymentNeeded', 'unpaid' => 'app:notPaidYet', 'pending' => 'app:paymentStarted', 'processing' => 'app:processing',
        'leg1_processing' => 'app:sending', 'completed' => 'app:paid', 'failed' => 'app:failedRetry',
    ];

    /** An order's payment as the filter reads it: none (nothing to pay), else its payment status (unpaid when empty). */
    private const PAYMENT_KEY = "CASE WHEN COALESCE(reviewer_orders.fee_amount, 0) <= 0 AND COALESCE(reviewer_orders.fee_type, 'flat') <> 'per_application' THEN 'none' ELSE COALESCE(reviewer_orders.payment_status, 'unpaid') END";
    private const FEE_TYPE_KEY = "CASE WHEN reviewer_orders.fee_type = 'per_application' THEN 'per_application' ELSE 'flat' END";
    /** Work the Business Coach still owes (an order past its deadline in one of these is overdue). */
    private const OPEN_WORK = ['assigned', 'pending', 'accepted', 'in_progress', 'modification_requested'];

    public function __construct(private int $userId)
    {
    }

    /** True when the request asks for the paged / searchable form. */
    public static function wanted(Request $request): bool
    {
        return ApiContract::wantsPagination($request) || $request->boolean('export')
            || $request->filled('search') || $request->filled('filters') || $request->filled('sort');
    }

    // ── review requests (GET /programs/reviewer/assigned-requests) ────────────────────────────────

    public function requests(Request $request): JsonResponse
    {
        $base = RoundReviewer::query()->where('round_reviewers.user_id', $this->userId)
            ->with(['programRound.program:id,program_title,currency,program_type,total_rounds', 'reviewerOrder']);
        // ?state=pending: only the requests still waiting for an answer (the filters stay free for the person)
        $state = in_array($request->query('state'), ['pending', 'accepted', 'declined'], true) ? $request->query('state') : null;
        if ($state) {
            $base->where('round_reviewers.acceptance_status', $state);
        }
        // what needs an answer first, unless the person picked a sort
        if (! $request->filled('sort')) {
            $base->orderByRaw("CASE WHEN round_reviewers.acceptance_status = 'pending' THEN 0 ELSE 1 END");
        }

        $list = ListQuery::for($base, $request)
            ->search(['programRound.round_name', 'program.program_title'])
            ->filter('id', 'id');
        // Described filters (filter_schema): the panel, chips and URL state of the pages that read it. With ?state= every
        // row has that status, so the status filter is not offered (it still filters).
        $state
            ? $list->filter('status', 'acceptance_status')
            : $list->options('status', 'acceptance_status', label: 'app:status', values: ['pending', 'accepted', 'declined'], optionLabels: self::REQUEST_LABELS);
        $list
            // program ids (older links sent program titles: still understood)
            ->options('program', fn (Builder $q, array $values) => $q->whereHas('programRound', fn (Builder $r) => $this->whereProgram($r, 'program_rounds.program_id', $values)),
                label: 'app:program', optionLabels: fn (array $ids) => self::programNames($ids), searchable: true,
                countBy: fn (Builder $q) => self::countRows($q->join('program_rounds as fr', 'fr.id', '=', 'round_reviewers.round_id'), 'fr.program_id'))
            ->options('fee', fn (Builder $q, array $values) => $this->whereFeeType($q, $values), label: 'app:exportFeeType',
                values: ['per_application', 'flat', 'none'], optionLabels: self::FEE_TYPE_LABELS, countBy: fn (Builder $q) => $this->feeTypeCounts($q))
            ->numberRange('amount', 'reviewer_fee', label: 'app:fee')
            ->dateRange('closes', fn (Builder $q, ?string $from, ?string $to) => $q->whereHas('programRound', fn (Builder $r) => self::betweenDays($r, 'program_rounds.close_date', $from, $to)),
                label: 'app:roundCloses', presets: self::PAST_AND_FUTURE)
            ->boolean('open', fn (Builder $q, bool $yes) => $q->whereHas('programRound', fn (Builder $r) => self::whereRoundOpen($r, $yes)), label: 'app:listFilterRoundOpen')
            ->dateRange('requested', 'created_at', label: 'app:requested')
            ->sortable(['created' => 'created_at', 'fee' => 'reviewer_fee', 'status' => 'acceptance_status'], default: '-created');
        [$rows, $page] = $list->fetch();

        $facets = $list->facets(['status']);
        $facets['program'] = $this->titleFacet($list->query('program', sorted: false)->reorder()
            ->join('program_rounds', 'program_rounds.id', '=', 'round_reviewers.round_id')
            ->join('programs', 'programs.id', '=', 'program_rounds.program_id'));
        $facets['fee'] = array_values(array_filter($list->facets(['fee'])['fee'] ?? [], fn ($o) => $o['count'] > 0));

        $counts = RoundReviewer::query()->where('user_id', $this->userId)
            ->selectRaw('acceptance_status, COUNT(*) as total')->groupBy('acceptance_status')->toBase()->pluck('total', 'acceptance_status');
        $stats = ['total' => (int) $counts->sum()];
        foreach (['pending', 'accepted', 'declined'] as $status) {
            $stats[$status] = (int) ($counts[$status] ?? 0);
        }

        return $this->respond('Review requests retrieved', $rows, $page, $list, $facets, $stats, ['assignment_requests'], $list->filterSchema());
    }

    // ── rounds the coach accepted (GET /programs/reviewer/assigned-rounds) ────────────────────────

    public function rounds(Request $request): JsonResponse
    {
        $accepted = DB::table('round_reviewers')->where('user_id', $this->userId)->where('acceptance_status', 'accepted')->select('round_id');
        $base = ProgramRound::query()->whereIn('program_rounds.id', $accepted)
            ->with('program:id,program_title,program_type')
            ->withCount(['applications' => fn ($q) => $q->whereIn('round_status', self::ROUND_APPLICATION_STATES)]);

        $me = $this->userId;
        $list = ListQuery::for($base, $request)
            ->search(['round_name', 'program.program_title'])
            ->filter('id', 'id')
            ->options('status', 'status', label: 'app:status', optionLabels: self::ROUND_LABELS)
            ->options('program', fn (Builder $q, array $values) => $this->whereProgram($q, 'program_rounds.program_id', $values),
                label: 'app:program', optionLabels: fn (array $ids) => self::programNames($ids), searchable: true, countBy: 'program_id')
            ->dateRange('closes', fn (Builder $q, ?string $from, ?string $to) => self::betweenDays($q, 'program_rounds.close_date', $from, $to),
                label: 'app:roundCloses', presets: self::PAST_AND_FUTURE)
            ->dateRange('opens', fn (Builder $q, ?string $from, ?string $to) => self::betweenDays($q, 'program_rounds.open_date', $from, $to),
                label: 'app:listFilterRoundOpens', presets: self::PAST_AND_FUTURE)
            ->numberRange('applications', DB::raw("(SELECT COUNT(*) FROM program_applications ra WHERE ra.current_round_id = program_rounds.id AND ra.round_status IN ('".implode("', '", self::ROUND_APPLICATION_STATES)."'))"), label: 'app:applications')
            ->numberRange('fee', DB::raw("(SELECT rf.reviewer_fee FROM round_reviewers rf WHERE rf.round_id = program_rounds.id AND rf.user_id = $me LIMIT 1)"), label: 'app:yourFee')
            ->boolean('open', fn (Builder $q, bool $yes) => self::whereRoundOpen($q, $yes), label: 'app:listFilterRoundOpen')
            ->boolean('to_review', fn (Builder $q, bool $yes) => $q->{$yes ? 'whereExists' : 'whereNotExists'}(fn ($sub) => $sub->from('reviewer_application_assignments as ta')
                ->whereColumn('ta.round_id', 'program_rounds.id')->where('ta.reviewer_id', $me)
                ->where(fn ($s) => $s->whereNull('ta.status')->orWhere('ta.status', '!=', 'completed'))), label: 'app:listFilterReviewsLeft')
            ->boolean('paid', fn (Builder $q, bool $yes) => $q->{$yes ? 'whereExists' : 'whereNotExists'}(fn ($sub) => $sub->from('reviewer_orders as po')
                ->whereColumn('po.round_id', 'program_rounds.id')->where('po.reviewer_id', $me)->where('po.order_type', 'round_review')
                ->where('po.payment_status', 'completed')), label: 'app:paid')
            ->sortable(['id' => 'id', 'round' => 'round_name', 'closes' => 'close_date', 'status' => 'status'], default: '-id');
        [$rows, $page] = $list->fetch();

        // The coach's own order for each round on the page: one query for all of them
        $orders = ReviewerOrder::query()->where('reviewer_id', $this->userId)->where('order_type', 'round_review')
            ->whereIn('round_id', $rows->pluck('id'))
            ->get(['id', 'round_id', 'fee_amount', 'fee_type', 'currency', 'payment_status', 'work_status'])
            ->keyBy('round_id');
        $rows = $rows->map(function (ProgramRound $round) use ($orders) {
            $row = $round->toArray();
            $row['applications_count'] = (int) $round->applications_count;
            $row['order'] = $orders->get($round->id)?->only(['id', 'fee_amount', 'fee_type', 'currency', 'payment_status', 'work_status']);

            return $row;
        });

        $facets = $list->facets(['status']);
        $facets['program'] = $this->titleFacet($list->query('program', sorted: false)->reorder()
            ->join('programs', 'programs.id', '=', 'program_rounds.program_id'));
        $stats = ['total' => (int) ProgramRound::query()->whereIn('id', $accepted)->count()];

        return $this->respond('Assigned rounds retrieved', $rows, $page, $list, $facets, $stats, ['rounds'], $list->filterSchema());
    }

    // ── the coach's applications in one round (GET /programs/rounds/{round}/my-applications) ───────

    public function roundApplications(Request $request, ProgramRound $round): JsonResponse
    {
        $base = ReviewerApplicationAssignment::query()->where('reviewer_application_assignments.reviewer_id', $this->userId)->where('reviewer_application_assignments.round_id', $round->id)
            ->with([
                'round.program',
                'application.program',
                'application.currentRound',
                'application.roundAnswers' => fn ($query) => $query->where('round_id', $round->id),
                'application.roundDocuments' => fn ($query) => $query->where('round_id', $round->id),
                'application.scores' => fn ($query) => $query->where('reviewer_id', $this->userId)->where('round_id', $round->id),
            ]);

        $list = ListQuery::for($base, $request)
            ->search(['application.startup_name', 'application.contact_person_name', 'application.contact_person_email', 'application.email'])
            ->filter('id', 'id')
            ->options('review', 'status', label: 'app:reviewStatus', values: ['assigned', 'in_review', 'completed'],
                optionLabels: ['assigned' => 'app:assigned', 'in_review' => 'app:inReview', 'completed' => 'app:completed'])
            ->options('status', fn (Builder $q, array $values) => $q->whereHas('application', fn (Builder $a) => $a->whereIn('status', $values)),
                label: 'app:applicationStatus2', countBy: fn (Builder $q) => self::countRows($this->joinApplication($q), 'program_applications.status'))
            ->options('sector', fn (Builder $q, array $values) => $q->whereHas('application', fn (Builder $a) => $a->whereIn('sector', $values)),
                label: 'app:sector', countBy: fn (Builder $q) => self::countRows($this->joinApplication($q), 'program_applications.sector'))
            // the coach's own score for the application in this round
            ->numberRange('score', DB::raw('(SELECT MAX(ms.total_score) FROM application_scores ms WHERE ms.application_id = reviewer_application_assignments.application_id AND ms.round_id = reviewer_application_assignments.round_id AND ms.reviewer_id = reviewer_application_assignments.reviewer_id)'), label: 'app:score')
            ->boolean('scored', fn (Builder $q, bool $yes) => $q->{$yes ? 'whereExists' : 'whereNotExists'}(fn ($sub) => $sub->from('application_scores as ss')
                ->whereColumn('ss.application_id', 'reviewer_application_assignments.application_id')
                ->whereColumn('ss.round_id', 'reviewer_application_assignments.round_id')
                ->whereColumn('ss.reviewer_id', 'reviewer_application_assignments.reviewer_id')), label: 'app:scored')
            ->dateRange('assigned', 'assigned_at', label: 'app:listFilterAssignedOn')
            ->dateRange('completed', 'completed_at', label: 'app:exportReviewCompleted')
            ->dateRange('submitted', fn (Builder $q, ?string $from, ?string $to) => $q->whereHas('application', fn (Builder $a) => self::betweenDays($a, 'program_applications.submitted_at', $from, $to)),
                label: 'app:submittedOn')
            ->sortable(['id' => 'id', 'assigned' => 'assigned_at', 'started' => 'started_at', 'completed' => 'completed_at', 'review' => 'status'], default: '-id');
        [$rows, $page] = $list->fetch();

        $facets = $list->facets(['review']);
        $facets['status'] = self::countRows($this->joinApplication($list->query('status', sorted: false)->reorder()), 'program_applications.status');

        $counts = ReviewerApplicationAssignment::query()->where('reviewer_id', $this->userId)->where('round_id', $round->id)
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->toBase()->pluck('total', 'status');
        $stats = ['total' => (int) $counts->sum()];
        foreach (['assigned', 'in_review', 'completed'] as $status) {
            $stats[$status] = (int) ($counts[$status] ?? 0);
        }
        // The coach's scores in this round, and what the round pays them (one payable total per order, not per row)
        $scores = \App\Models\Programs\Rounds\ApplicationScore::query()->where('reviewer_id', $this->userId)->where('round_id', $round->id)
            ->whereIn('application_id', ReviewerApplicationAssignment::query()->where('reviewer_id', $this->userId)->where('round_id', $round->id)->select('application_id'))
            ->selectRaw('COUNT(DISTINCT application_id) as scored, AVG(total_score) as average')->toBase()->first();
        $stats['scored'] = (int) ($scores->scored ?? 0);
        $stats['average_score'] = $stats['scored'] ? round((float) $scores->average, 1) : null;
        $stats['fees'] = ReviewerOrder::query()->where('reviewer_id', $this->userId)->where('round_id', $round->id)->where('order_type', 'round_review')
            ->selectRaw("COALESCE(currency, 'KES') as cur, COALESCE(SUM(fee_amount), 0) as total, COALESCE(SUM(CASE WHEN payment_status = 'completed' THEN fee_amount ELSE 0 END), 0) as paid")
            ->groupBy(DB::raw("COALESCE(currency, 'KES')"))->toBase()->get()
            ->mapWithKeys(fn ($row) => [$row->cur => ['total' => round((float) $row->total, 2), 'paid' => round((float) $row->paid, 2)]])->all();

        return $this->respond('Assigned applications retrieved', $rows, $page, $list, $facets, $stats, [], $list->filterSchema());
    }

    // ── the coach's orders (GET /programs/reviewer/orders) ────────────────────────────────────────

    /** @param callable(Collection): Collection $shape turns a page of orders into the rows the old list sent */
    public function orders(Request $request, Builder $base, callable $shape): JsonResponse
    {
        $list = ListQuery::for($base, $request)
            ->search(['id', 'program.program_title', 'round.round_name'])
            ->filter('id', 'id')
            ->options('work', 'work_status', label: 'app:workStatus', values: array_keys(self::WORK_LABELS), optionLabels: self::WORK_LABELS)
            ->options('payment', fn (Builder $q, array $values) => $this->wherePayment($q, $values), label: 'app:payment',
                values: array_keys(self::PAYMENT_LABELS), optionLabels: self::PAYMENT_LABELS, countBy: DB::raw(self::PAYMENT_KEY))
            ->options('type', 'order_type', label: 'app:listFilterOrderType', optionLabels: ['round_review' => 'app:roundReview', 'site_visit' => 'app:siteVisit'])
            ->entity('program', 'program_id', fn (array $ids) => self::programNames($ids), label: 'app:program')
            ->entity('round', 'round_id', fn (array $ids) => self::roundNames($ids), label: 'app:round2')
            ->options('fee_type', DB::raw(self::FEE_TYPE_KEY), label: 'app:exportFeeType', values: ['per_application', 'flat'], optionLabels: self::FEE_TYPE_LABELS)
            ->options('currency', DB::raw("UPPER(COALESCE(reviewer_orders.currency, 'KES'))"), label: 'app:currency')
            ->numberRange('fee', 'fee_amount', label: 'app:fee')
            ->boolean('overdue', fn (Builder $q, bool $yes) => $this->whereOverdue($q, $yes), label: 'app:overdue')
            ->boolean('awaiting_payment', fn (Builder $q, bool $yes) => $this->whereAwaitingPayment($q, $yes), label: 'app:listFilterAwaitingPayment')
            ->dateRange('deadline', 'deadline', label: 'app:deadline2', presets: self::PAST_AND_FUTURE)
            ->dateRange('created', 'created_at', label: 'app:created')
            ->dateRange('paid', 'paid_at', label: 'app:listFilterPaidOn')
            ->sortable(['id' => 'id', 'fee' => 'fee_amount', 'deadline' => 'deadline', 'created' => 'created_at'], default: '-created');
        [$rows, $page] = $list->fetch();

        $facets = $list->facets(['work', 'type']);
        $facets['payment'] = $list->facets(['payment'])['payment'] ?? [];

        // Totals over every order of the coach, kept per currency (KES and USD are never added together)
        $byCurrency = (clone $base)->reorder()->toBase()
            ->selectRaw("COALESCE(currency, 'KES') as cur")
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw("SUM(CASE WHEN work_status IN ('delivered', 'approved') THEN 1 ELSE 0 END) as completed_count")
            ->selectRaw('COALESCE(SUM(fee_amount), 0) as earnings')
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_status = 'completed' THEN fee_amount ELSE 0 END), 0) as paid")
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_status IN ('unpaid', 'pending', 'leg1_processing') AND work_status IN ('delivered', 'approved') THEN fee_amount ELSE 0 END), 0) as pending_payment")
            ->groupBy(DB::raw("COALESCE(currency, 'KES')"))->get()
            ->mapWithKeys(fn ($row) => [$row->cur => [
                'total_count' => (int) $row->total_count,
                'completed_count' => (int) $row->completed_count,
                'earnings' => round((float) $row->earnings, 2),
                'paid' => round((float) $row->paid, 2),
                'pending_payment' => round((float) $row->pending_payment, 2),
            ]]);
        $stats = [
            'total' => (int) $byCurrency->sum('total_count'),
            'completed' => (int) $byCurrency->sum('completed_count'),
            'by_currency' => $byCurrency->all(),
        ];
        $stats['in_progress'] = $stats['total'] - $stats['completed'];

        return $this->respond('Orders retrieved', $shape($rows), $page, $list, $facets, $stats, [], $list->filterSchema());
    }

    // ── the coach's site visits (GET /programs/monitoring/reviewer/{id}/site-visits) ──────────────

    public function siteVisits(Request $request): JsonResponse
    {
        $base = MESiteVisit::sent()->where('m_e_site_visits.reviewer_id', $this->userId)
            ->with(['checkpoint.application.program:id,program_title', 'checkpoint.application.user:id,image', 'files', 'reviewerOrder', 'reviewer']);
        if (! $request->filled('sort')) {
            $visits = $base->getModel()->getTable();
            $open = '('.$this->stageRankSql($visits).') <= 1';
            $base->orderByRaw($this->stageRankSql($visits))
                ->orderByRaw("CASE WHEN $open THEN $visits.start_date END asc")
                ->orderByRaw("CASE WHEN NOT ($open) THEN $visits.start_date END desc");
        }

        $visits = $base->getModel()->getTable();
        $orderOf = fn (string $column) => "(SELECT vo.$column FROM reviewer_orders vo WHERE vo.site_visit_id = $visits.id ORDER BY vo.id LIMIT 1)";
        $list = ListQuery::for($base, $request)
            ->search(['objective', 'location', 'checkpoint.checkpoint_name', 'application.startup_name'])
            ->filter('id', 'id')
            ->options('status', fn (Builder $q, array $values) => $q->where(function (Builder $any) use ($values) {
                foreach (array_intersect(self::STAGES, $values) as $stage) {
                    $any->orWhere(fn (Builder $one) => $this->whereStage($one, $stage));
                }
            }), label: 'app:status', values: self::STAGES, optionLabels: self::STAGE_LABELS, countBy: fn (Builder $q) => array_map(
                fn ($stage) => ['value' => $stage, 'count' => (clone $q)->where(fn (Builder $one) => $this->whereStage($one, $stage))->count()],
                self::STAGES,
            ))
            ->options('checkpoint', fn (Builder $q, array $values) => $q->whereHas('checkpoint', fn (Builder $c) => $c->whereIn('checkpoint_name', $values)),
                label: 'app:checkpoint', countBy: fn (Builder $q) => self::countRows($q->join('m_e_checkpoints as fc', 'fc.id', '=', "$visits.checkpoint_id"), 'fc.checkpoint_name'))
            ->options('program', fn (Builder $q, array $values) => $q->whereHas('checkpoint', fn (Builder $c) => $c->whereIn('m_e_checkpoints.program_id', $values)),
                label: 'app:program', optionLabels: fn (array $ids) => self::programNames($ids), searchable: true,
                countBy: fn (Builder $q) => self::countRows($q->join('m_e_checkpoints as pc', 'pc.id', '=', "$visits.checkpoint_id"), 'pc.program_id'))
            ->entity('business', 'app_id', fn (array $ids) => \App\Models\Programs\ProgramApplication::whereIn('id', $ids)->pluck('startup_name', 'id')->all(), label: 'app:business')
            ->options('location', fn (Builder $q, array $values) => $q->where(function (Builder $any) use ($values) {
                foreach ($values as $place) {
                    $any->orWhereRaw("m_e_site_visits.location like ? escape '!'", ['%'.ListQuery::escapeLike((string) $place)]);
                }
            }), label: 'app:location', searchable: true, countBy: fn (Builder $q) => $this->placeCounts($q))
            ->options('type', DB::raw("CASE WHEN $visits.assign_type = 'third_party_audit' THEN 'third_party_audit' ELSE 'site_visit' END"),
                label: 'app:supportType', values: ['site_visit', 'third_party_audit'], optionLabels: ['site_visit' => 'app:siteVisit', 'third_party_audit' => 'app:thirdPartyAudit'])
            ->dateRange('date', fn (Builder $q, ?string $from, ?string $to) => self::betweenDays($q, "$visits.start_date", $from, $to), label: 'app:visitDate', presets: self::PAST_AND_FUTURE)
            ->numberRange('fee', DB::raw($orderOf('fee_amount')), label: 'app:fee')
            ->boolean('upcoming', fn (Builder $q, bool $yes) => $this->whereVisitDue($q, '>=', $yes), label: 'app:upcoming')
            ->boolean('overdue', fn (Builder $q, bool $yes) => $this->whereVisitDue($q, '<', $yes), label: 'app:overdue')
            ->boolean('paid', fn (Builder $q, bool $yes) => $q->{$yes ? 'whereHas' : 'whereDoesntHave'}('reviewerOrder', fn (Builder $o) => $o->where('payment_status', 'completed')), label: 'app:paid')
            ->boolean('files', fn (Builder $q, bool $yes) => $q->{$yes ? 'whereHas' : 'whereDoesntHave'}('files'), label: 'app:listFilterHasFiles')
            ->sortable(['date' => 'start_date', 'objective' => 'objective', 'location' => 'location'], default: null);
        [$rows, $page] = $list->fetch();

        $items = $rows->map(function (MESiteVisit $visit) use ($request) {
            $row = (new MESiteVisitResource($visit))->toArray($request);
            $application = $visit->checkpoint?->application;
            $row['program'] = $application?->program?->program_title;
            $row['application'] = $application ? $application->only([
                'id', 'uid', 'startup_name', 'business_id', 'sector', 'stage', 'headquarters_location',
                'contact_person_name', 'contact_person_email', 'contact_person_phone', 'email',
                'team_experience_avg_years', 'social_impact_areas',
            ]) + ['user' => $application->user?->only(['id', 'image'])] : null;

            return $row;
        });

        // `facets` stays for older screens (non-empty options only); the panel reads filter_schema
        $facets = collect($list->facets(['status', 'checkpoint', 'location']))
            ->map(fn (array $options) => array_values(array_filter($options, fn ($o) => $o['count'] > 0)))->all();

        return $this->respond('Site visits fetched successfully', $items, $page, $list, $facets, $this->siteVisitStats(), [], $list->filterSchema());
    }

    /** Stage counts, upcoming visits and fees over every visit of the coach (two light queries). */
    private function siteVisitStats(): array
    {
        $visits = MESiteVisit::sent()->where('reviewer_id', $this->userId)->get(['id', 'status', 'start_date']);
        $orders = ReviewerOrder::query()->whereIn('site_visit_id', $visits->pluck('id'))
            ->get(['id', 'site_visit_id', 'acceptance_status', 'fee_amount', 'currency', 'payment_status'])
            ->keyBy('site_visit_id');

        $today = now()->startOfDay();
        $stages = array_fill_keys(self::STAGES, 0);
        $upcoming = 0;
        $thisWeek = 0;
        $overdue = 0;
        $next = null;
        $fees = [];
        foreach ($visits as $visit) {
            $order = $orders->get($visit->id);
            $stage = self::stageOf($visit->status, $order?->acceptance_status, (bool) $order);
            $stages[$stage]++;
            if ($stage === 'active' && $visit->start_date) {
                $days = (int) $today->diffInDays($visit->start_date->copy()->startOfDay(), false);
                if ($days >= 0) {
                    $upcoming++;
                    $thisWeek += $days <= 7 ? 1 : 0;
                    $next = ! $next || $visit->start_date->lt($next) ? $visit->start_date : $next;
                } else {
                    $overdue++;
                }
            }
            if ($order && (float) $order->fee_amount > 0 && ! in_array($stage, ['declined', 'cancelled'], true)) {
                $currency = $order->currency ?: 'KES';
                $fees[$currency] ??= ['count' => 0, 'total' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0];
                $fees[$currency]['count']++;
                $fees[$currency]['total'] += (float) $order->fee_amount;
                $fees[$currency][$order->payment_status === 'completed' ? 'paid' : 'unpaid'] += (float) $order->fee_amount;
            }
        }

        return [
            'total' => $visits->count(),
            'stages' => $stages,
            'upcoming' => $upcoming,
            'this_week' => $thisWeek,
            'overdue' => $overdue,
            'next_date' => $next?->toDateString(),
            'fees' => $fees,
        ];
    }

    /** The same stage the frontend shows (utils/siteVisitStage.js). */
    public static function stageOf(?string $status, ?string $acceptance, bool $hasOrder): string
    {
        $status = strtolower((string) $status) ?: 'scheduled';
        $acceptance = strtolower((string) $acceptance);
        if ($status === 'completed') {
            return 'completed';
        }
        if ($acceptance === 'declined') {
            return 'declined';
        }
        if ($status === 'cancelled') {
            return 'cancelled';
        }

        return $hasOrder && $acceptance === 'pending' ? 'pending' : 'active';
    }

    private function whereStage(Builder $q, string $stage): void
    {
        $declined = fn (Builder $o) => $o->where('acceptance_status', 'declined');
        $status = $q->getModel()->qualifyColumn('status');
        match ($stage) {
            'completed' => $q->where($status, 'completed'),
            'declined' => $q->where(fn (Builder $s) => $s->whereNull($status)->orWhere($status, '!=', 'completed'))->whereHas('reviewerOrder', $declined),
            'cancelled' => $q->where($status, 'cancelled')->whereDoesntHave('reviewerOrder', $declined),
            'pending' => $q->where(fn (Builder $s) => $s->whereNull($status)->orWhereNotIn($status, ['completed', 'cancelled']))
                ->whereDoesntHave('reviewerOrder', $declined)
                ->whereHas('reviewerOrder', fn (Builder $o) => $o->where('acceptance_status', 'pending')),
            default => $q->where(fn (Builder $s) => $s->whereNull($status)->orWhereNotIn($status, ['completed', 'cancelled']))
                ->whereDoesntHave('reviewerOrder', fn (Builder $o) => $o->whereIn('acceptance_status', ['declined', 'pending'])),
        };
    }

    /** 0 pending, 1 active, 2 completed, 3 declined, 4 cancelled, as SQL (the default order of the list). */
    private function stageRankSql(string $visits): string
    {
        $order = fn (string $acceptance) => "EXISTS (SELECT 1 FROM reviewer_orders o WHERE o.site_visit_id = $visits.id AND o.acceptance_status = '$acceptance')";

        return "CASE WHEN $visits.status = 'completed' THEN 2 WHEN {$order('declined')} THEN 3 WHEN $visits.status = 'cancelled' THEN 4 WHEN {$order('pending')} THEN 0 ELSE 1 END";
    }

    /** "Westlands, Nairobi, Kenya" -> "Nairobi, Kenya" (listData.placeOf). */
    public static function placeOf(?string $location): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', (string) $location)), fn ($p) => $p !== ''));

        return implode(', ', array_slice($parts, -2));
    }

    // ── a service provider's program work (GET /programs/provider/work) ───────────────────────────

    public static function providerWork(Request $request, Collection $rows): JsonResponse
    {
        $today = now()->toDateString();
        $has = fn (string $field) => $rows->contains(fn ($r) => ($r[$field] ?? null) !== null && ($r[$field] ?? null) !== '');
        $distinct = fn (string $field) => $rows->pluck($field)->filter(fn ($v) => $v !== null && $v !== '')->unique()->count();

        $list = CollectionList::for($rows, $request)
            ->search(fn ($r) => implode(' ', [$r['reference'] ?? '', $r['program'] ?? '', $r['business'] ?? '', $r['milestone'] ?? '', $r['support_type'] ?? '', $r['location'] ?? '']))
            ->filter('id', fn ($r) => $r['id']);
        // Described only when this person's work can answer them (one kind of work has no "kind" to choose, a page
        // without fees no fee range); the keys still filter either way, so links keep working.
        if ($distinct('kind') > 1) {
            $list->options('kind', fn ($r) => $r['kind'], label: 'app:listFilterWorkType', optionLabels: self::WORK_KIND_LABELS);
        } else {
            $list->filter('kind', fn ($r) => $r['kind']);
        }
        $list->options('status', fn ($r) => $r['status'], label: 'app:status')
            ->options('program', fn ($r) => $r['program'], label: 'app:program');
        if ($has('business')) {
            $list->options('business', fn ($r) => $r['business'] ?? null, label: 'app:business');
        }
        if ($distinct('support_type') > 1) {
            $list->options('support', fn ($r) => $r['support_type'], label: 'app:supportType');
        } else {
            $list->filter('support', fn ($r) => $r['support_type']);
        }
        if ($has('amount')) {
            $list->numberRange('amount', fn ($r) => $r['amount'] ?? null, label: 'app:amount');
        }
        if ($has('payment_status')) {
            $list->boolean('paid', fn ($r) => ($r['payment_status'] ?? null) === 'completed', label: 'app:paid');
        }
        $list->dateRange('due', fn ($r) => $r['due'] ?? '', label: 'app:due2', presets: self::PAST_AND_FUTURE)
            ->boolean('upcoming', fn ($r) => ($r['due'] ?? '') !== '' && substr((string) $r['due'], 0, 10) >= $today, label: 'app:upcoming')
            ->boolean('answer', fn ($r) => self::needsAnswer($r), label: 'app:listFilterNeedsAnswer')
            ->sortable([
                'recent' => fn ($r) => $r['sort'] ?? 0,
                'reference' => fn ($r) => $r['reference'] ?? '',
                'program' => fn ($r) => $r['program'] ?? '',
                'due' => fn ($r) => $r['due'] ?? '',
                'amount' => fn ($r) => $r['amount'] ?? 0,
                'status' => fn ($r) => $r['status'] ?? '',
            ], default: '-recent');
        [$items, $page] = $list->fetch();

        $data = [
            'items' => $items->map(fn ($row) => collect($row)->except('sort')->all())->values(),
            'facets' => $list->facets(['status', 'program', 'support']),
            'filter_schema' => $list->filterSchema(),
            'stats' => ['total' => $rows->count()],
        ];
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }
        if ($list->wantsExport()) {
            $data['export_truncated'] = $list->exportTruncated();
        }

        return response()->json(['success' => true, 'message' => 'Program work retrieved', 'data' => $data]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────────

    /** Program ids (or, from older links, program titles) on a query that has $column = the program id. */
    private function whereProgram(Builder $q, string $column, array $values): void
    {
        $ids = array_values(array_filter($values, fn ($v) => ctype_digit((string) $v)));
        $titles = array_values(array_diff($values, $ids));
        $q->where(function (Builder $any) use ($column, $ids, $titles) {
            if ($ids) {
                $any->orWhereIn($column, $ids);
            }
            if ($titles) {
                $any->orWhereIn($column, \App\Models\Programs\Program::query()->whereIn('program_title', $titles)->select('id'));
            }
        });
    }

    /** @return array<int, string> program id => title */
    private static function programNames(array $ids): array
    {
        return \App\Models\Programs\Program::query()->whereIn('id', $ids)->pluck('program_title', 'id')->all();
    }

    /** @return array<int, string> round id => name */
    private static function roundNames(array $ids): array
    {
        return ProgramRound::query()->whereIn('id', $ids)->get(['id', 'round_number', 'round_name'])
            ->mapWithKeys(fn ($r) => [$r->id => $r->round_name ?: 'Round '.$r->round_number])->all();
    }

    /** [{value, count}] of $column over a query (the 100 most used values). */
    private static function countRows(Builder $q, string $column): array
    {
        return $q->whereNotNull($column)
            ->selectRaw("$column as value, COUNT(*) as count")
            ->groupBy($column)->orderByDesc('count')->limit(100)->toBase()->get()
            ->map(fn ($row) => ['value' => $row->value, 'count' => (int) $row->count])->all();
    }

    private function joinApplication(Builder $q): Builder
    {
        return $q->join('program_applications', 'program_applications.id', '=', 'reviewer_application_assignments.application_id');
    }

    /** Whole days from~to on a date or datetime column (compared as days, so a plain date column works everywhere). */
    private static function betweenDays(Builder $q, string $column, ?string $from, ?string $to): void
    {
        if ($from) {
            $q->whereDate($column, '>=', $from);
        }
        if ($to) {
            $q->whereDate($column, '<=', $to);
        }
    }

    /**
     * A round still open: it closes at an exact time (program_rounds.closes_at, UTC) that has not passed yet; a round
     * without one falls back to its close day; a round with neither is open.
     */
    private static function whereRoundOpen(Builder $q, bool $yes): void
    {
        $now = now()->utc()->toDateTimeString();
        $today = now()->toDateString();
        $open = fn (Builder $w) => $w
            ->where(fn (Builder $t) => $t->whereNotNull('program_rounds.closes_at')->where('program_rounds.closes_at', '>', $now))
            ->orWhere(fn (Builder $d) => $d->whereNull('program_rounds.closes_at')
                ->where(fn (Builder $c) => $c->whereNull('program_rounds.close_date')->orWhereDate('program_rounds.close_date', '>=', $today)));

        $yes ? $q->where($open) : $q->whereNot($open);
    }

    /** [{value, count}] of the request fee types (per application, flat, none). */
    private function feeTypeCounts(Builder $q): array
    {
        $fee = $q->selectRaw(
            "SUM(CASE WHEN COALESCE(reviewer_fee, 0) <= 0 THEN 1 ELSE 0 END) as none_count,
             SUM(CASE WHEN COALESCE(reviewer_fee, 0) > 0 AND fee_type = 'per_application' THEN 1 ELSE 0 END) as per_count,
             SUM(CASE WHEN COALESCE(reviewer_fee, 0) > 0 AND COALESCE(fee_type, 'flat') <> 'per_application' THEN 1 ELSE 0 END) as flat_count"
        )->toBase()->first();

        return [
            ['value' => 'per_application', 'count' => (int) ($fee->per_count ?? 0)],
            ['value' => 'flat', 'count' => (int) ($fee->flat_count ?? 0)],
            ['value' => 'none', 'count' => (int) ($fee->none_count ?? 0)],
        ];
    }

    /** Overdue: the deadline has passed and the work is still open (not delivered, approved or rejected). */
    private function whereOverdue(Builder $q, bool $yes): void
    {
        $overdue = fn (Builder $w) => $w->whereNotNull('reviewer_orders.deadline')->where('reviewer_orders.deadline', '<', now())
            ->whereIn('reviewer_orders.work_status', self::OPEN_WORK);
        $yes ? $q->where($overdue) : $q->whereNot($overdue);
    }

    /** Awaiting payment: the work is delivered or approved, there is a fee, and it is not paid yet. */
    private function whereAwaitingPayment(Builder $q, bool $yes): void
    {
        $waiting = fn (Builder $w) => $w->where('reviewer_orders.fee_amount', '>', 0)
            ->whereIn('reviewer_orders.work_status', ['delivered', 'approved'])
            ->where(fn (Builder $p) => $p->whereNull('reviewer_orders.payment_status')->orWhere('reviewer_orders.payment_status', '!=', 'completed'));
        $yes ? $q->where($waiting) : $q->whereNot($waiting);
    }

    /** An active visit (not pending, done, declined or cancelled) whose day is $op today: upcoming (>=) or overdue (<). */
    private function whereVisitDue(Builder $q, string $op, bool $yes): void
    {
        $start = $q->getModel()->qualifyColumn('start_date');
        $due = fn (Builder $w) => $w->where(fn (Builder $a) => $this->whereStage($a, 'active'))
            ->whereNotNull($start)->whereDate($start, $op, now()->toDateString());
        $yes ? $q->where($due) : $q->whereNot($due);
    }

    /** [{value, count}] of the visits' places ("Nairobi, Kenya"), the last two parts of each location. */
    private function placeCounts(Builder $q): array
    {
        return $q->selectRaw('m_e_site_visits.location as location, COUNT(*) as count')->groupBy('m_e_site_visits.location')->toBase()->get()
            ->groupBy(fn ($row) => self::placeOf($row->location))
            ->filter(fn ($group, $place) => $place !== '')
            ->map(fn ($group, $place) => ['value' => $place, 'count' => (int) $group->sum('count')])
            ->sortByDesc('count')->values()->take(100)->all();
    }

    /** Program work that waits for the provider's yes or no (a review request, a site visit order not answered yet). */
    private static function needsAnswer(array $row): bool
    {
        return match ($row['kind'] ?? null) {
            'review_request' => ($row['status'] ?? null) === 'pending',
            'inspection' => ($row['acceptance_status'] ?? null) === 'pending',
            default => false,
        };
    }

    private function whereFeeType(Builder $q, array $values): void
    {
        $q->where(function (Builder $any) use ($values) {
            if (in_array('none', $values, true)) {
                $any->orWhere(fn (Builder $n) => $n->whereNull('round_reviewers.reviewer_fee')->orWhere('round_reviewers.reviewer_fee', '<=', 0));
            }
            if (in_array('per_application', $values, true)) {
                $any->orWhere(fn (Builder $p) => $p->where('round_reviewers.reviewer_fee', '>', 0)->where('round_reviewers.fee_type', 'per_application'));
            }
            if (in_array('flat', $values, true)) {
                $any->orWhere(fn (Builder $f) => $f->where('round_reviewers.reviewer_fee', '>', 0)->where(fn (Builder $t) => $t->whereNull('round_reviewers.fee_type')->orWhere('round_reviewers.fee_type', '!=', 'per_application')));
            }
        });
    }

    private function wherePayment(Builder $q, array $values): void
    {
        $none = fn (Builder $n) => $n->where(fn (Builder $a) => $a->whereNull('fee_amount')->orWhere('fee_amount', '<=', 0))
            ->where(fn (Builder $t) => $t->whereNull('fee_type')->orWhere('fee_type', '!=', 'per_application'));
        $statuses = array_values(array_diff($values, ['none']));
        $q->where(function (Builder $any) use ($values, $statuses, $none) {
            if (in_array('none', $values, true)) {
                $any->orWhere($none);
            }
            if ($statuses) {
                $any->orWhere(fn (Builder $s) => $s->whereNot($none)->where(function (Builder $p) use ($statuses) {
                    $p->whereIn('payment_status', $statuses);
                    if (in_array('unpaid', $statuses, true)) {
                        $p->orWhereNull('payment_status');
                    }
                }));
            }
        });
    }

    /** Option counts by program title over a query that already joins `programs`. */
    private function titleFacet(Builder $joined): array
    {
        return $joined->whereNotNull('programs.program_title')
            ->selectRaw('programs.program_title as value, COUNT(*) as count')
            ->groupBy('programs.program_title')->orderByDesc('count')->limit(100)->toBase()->get()
            ->map(fn ($row) => ['value' => $row->value, 'count' => (int) $row->count])->all();
    }

    private function respond(string $message, Collection $rows, $page, ListQuery $list, array $facets, array $stats, array $legacy, array $schema = []): JsonResponse
    {
        $items = $rows->values();
        $data = ['items' => $items, 'facets' => $facets, 'filter_schema' => $schema, 'stats' => $stats];
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }
        if ($list->wantsExport()) {
            $data['export_truncated'] = $list->exportTruncated();
        }

        $body = ['success' => true, 'message' => $message, 'data' => $data];
        foreach ($legacy as $key) {
            $body[$key] = $items;
        }

        return response()->json($body);
    }
}
