<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Models\Capital\StartupPitches;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\NotificationService;
use App\Service\Program\RubricCalculator;
use Carbon\CarbonImmutable;
use DateTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyticsController extends Controller
{
    public $notification;

    /** Period filter → how far back submissions are counted (null = everything) */
    private const PERIODS = ['30_days', '3_months', '6_months', '12_months', 'all_time'];

    /** Application statuses in the order the page shows them (drafts are not applications yet) */
    private const STATUS_ORDER = ['pending', 'under_review', 'approved', 'waitlisted', 'awarded', 'in_monitoring', 'completed', 'rejected', 'withdrawn'];

    /** Score bands, as a percent of the round's highest possible total */
    private const SCORE_BANDS = ['90_100' => 90, '80_89' => 80, '70_79' => 70, '60_69' => 60, '0_59' => 0];

    private const AI_WORKING = ['queued', 'extracting', 'analyzing'];

    public function __construct()
    {
        $this->notification = new NotificationService();
    }

    /**
     * GET programs/analytics?program_id=&round_id=&period=
     *
     * Program analytics for the signed-in program organization (team members see their organization owner's programs),
     * built from the programs' own rounds, statuses, Business Coach / AI reviews and each round's own scoring criteria
     * (see BACKEND_DASHBOARD_STATS_AND_PROGRAM_ANALYTICS.txt §3). Counts are done by the database; drafts are never
     * counted as applications. Numbers, ISO currency, stable keys and ISO-8601 dates only.
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            if ((int) $user->user_type_id !== 4) {
                return response()->json(['success' => false, 'message' => 'Only program organizations have program analytics.'], 403);
            }
            $ownerId = $user->organizationOwnerId();

            $validated = $request->validate([
                'program_id' => 'nullable|integer',
                'round_id' => 'nullable|integer',
                'period' => 'nullable|string|in:' . implode(',', self::PERIODS),
                // How many top applications: a number (default 10, max 1000) or "all" (the PDF / file export)
                'top' => ['nullable', 'regex:/^(all|[1-9][0-9]{0,3})$/'],
            ]);
            $topLimit = ($validated['top'] ?? null) === 'all' ? null : min(1000, (int) ($validated['top'] ?? 10));
            $period = $validated['period'] ?? '12_months';

            $allPrograms = Program::query()
                ->ofOrganization($user) // ISSUE-38
                ->select(['id', 'program_title', 'status', 'currency', 'use_match_score', 'match_score_limit', 'total_program_amount', 'created_at'])
                ->orderByDesc('created_at')
                ->get();
            $allRounds = ProgramRound::query()
                ->whereIn('program_id', $allPrograms->pluck('id'))
                ->select(['id', 'program_id', 'round_number', 'round_name', 'status', 'review_method', 'scoring_criteria', 'rubric_mode', 'min_reviewers_required'])
                ->orderBy('program_id')->orderBy('round_number')
                ->get();

            // Access: a program or round outside the organization is refused, not silently ignored
            $programId = isset($validated['program_id']) ? (int) $validated['program_id'] : null;
            $roundId = isset($validated['round_id']) ? (int) $validated['round_id'] : null;
            if ($programId && !$allPrograms->contains('id', $programId)) {
                return response()->json(['success' => false, 'code' => 'forbidden', 'message' => "You don't have access to this. It may belong to a different account."], 403);
            }
            $round = $roundId ? $allRounds->firstWhere('id', $roundId) : null;
            if ($roundId && !$round) {
                return response()->json(['success' => false, 'code' => 'forbidden', 'message' => "You don't have access to this. It may belong to a different account."], 403);
            }
            if ($round && $programId && (int) $round->program_id !== $programId) {
                return response()->json(['success' => false, 'message' => 'This round belongs to a different program.', 'errors' => ['round_id' => ['This round belongs to a different program.']]], 422);
            }
            if ($round && !$programId) $programId = (int) $round->program_id;

            $programs = $programId ? $allPrograms->where('id', $programId)->values() : $allPrograms;
            $rounds = $allRounds->whereIn('program_id', $programs->pluck('id'))->values();
            $roundsById = $rounds->keyBy('id');
            $programIds = $programs->pluck('id')->all();

            $now = CarbonImmutable::now();
            $from = match ($period) {
                '30_days' => $now->subDays(30),
                '3_months' => $now->subMonthsNoOverflow(3),
                '6_months' => $now->subMonthsNoOverflow(6),
                '12_months' => $now->subMonthsNoOverflow(12),
                default => null,
            };

            // When the application was submitted: its first round submission, or when it was created (older data)
            $submittedAt = '(COALESCE((SELECT MIN(h0.submitted_at) FROM application_round_history h0 WHERE h0.application_id = a.id), a.created_at))';

            /** Submitted applications in scope (program, round, period) */
            $base = function () use ($programIds, $roundId, $from, $submittedAt): Builder {
                $q = DB::table('program_applications as a')
                    ->whereIn('a.program_id', $programIds ?: [0])
                    ->where('a.status', '!=', 'draft');
                if ($roundId) {
                    $q->where(fn ($w) => $w->where('a.current_round_id', $roundId)
                        ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('application_round_history as hr')
                            ->whereColumn('hr.application_id', 'a.id')->where('hr.round_id', $roundId)));
                }
                if ($from) $q->whereRaw("$submittedAt >= ?", [$from->toDateTimeString()]);

                return $q;
            };
            $appIds = fn (): Builder => $base()->select('a.id');

            $advancedSql = "CASE WHEN a.round_status = 'advanced' OR EXISTS (SELECT 1 FROM application_round_history h1 WHERE h1.application_id = a.id AND h1.outcome = 'advanced'"
                . ($roundId ? ' AND h1.round_id = ' . $roundId : '') . ') THEN 1 ELSE 0 END';

            // ── One grouped pass: program × status × round status ─────────────────────────────────
            $groups = $base()
                ->groupBy('a.program_id', 'a.status', 'a.round_status')
                ->selectRaw("a.program_id, a.status, a.round_status, COUNT(*) as total, SUM($advancedSql) as advanced,
                    SUM(CASE WHEN a.status IN ('awarded','completed') THEN COALESCE(a.awarded_amount, 0) ELSE 0 END) as awarded_amount,
                    SUM(CASE WHEN a.match_score IS NOT NULL THEN 1 ELSE 0 END) as match_scored, SUM(COALESCE(a.match_score, 0)) as match_sum")
                ->get()
                ->map(function ($row) {
                    $row->status = strtolower((string) $row->status);
                    $row->round_status = strtolower((string) $row->round_status);
                    return $row;
                });

            $drafts = DB::table('program_applications as a')->whereIn('a.program_id', $programIds ?: [0])->where('a.status', 'draft')
                ->when($roundId, fn ($q) => $q->where('a.current_round_id', $roundId))
                ->when($from, fn ($q) => $q->where('a.created_at', '>=', $from->toDateTimeString()))
                ->count();

            // ── Scores: one row per application × round ───────────────────────────────────────────
            $scoreRows = DB::table('application_scores as s')
                ->whereIn('s.application_id', $appIds())
                ->when($roundId, fn ($q) => $q->where('s.round_id', $roundId))
                ->groupBy('s.application_id', 's.round_id')
                ->selectRaw("s.application_id, s.round_id, AVG(s.total_score) as avg_total,
                    SUM(CASE WHEN s.source = 'ai' THEN 1 ELSE 0 END) as ai_scores,
                    SUM(CASE WHEN s.source = 'ai' THEN 0 ELSE 1 END) as human_scores")
                ->get();

            $calculator = app(RubricCalculator::class);
            $roundMax = $rounds->mapWithKeys(fn ($r) => [$r->id => $this->roundMaxTotal($r, $calculator)]);

            // Each application's score: the filtered round, otherwise the latest round it was scored in
            $appScores = $scoreRows->groupBy('application_id')->map(function (Collection $rows) use ($roundsById, $roundMax) {
                $row = $rows->sortByDesc(fn ($r) => (int) ($roundsById[$r->round_id]->round_number ?? 0))->first();
                $max = (float) ($roundMax[$row->round_id] ?? 100) ?: 100;

                return [
                    'round_id' => (int) $row->round_id,
                    'score' => round((float) $row->avg_total, 2),
                    'max_score' => round($max, 2),
                    'percent' => round(min(100, max(0, (float) $row->avg_total / $max * 100)), 1),
                ];
            });

            // ── Summary ──────────────────────────────────────────────────────────────────────────
            $sum = fn (Collection $rows, ?callable $filter = null) => (int) ($filter ? $rows->filter($filter) : $rows)->sum('total');
            $isAwarded = fn ($g) => in_array($g->status, ['awarded', 'in_monitoring', 'completed'], true);
            $submitted = $sum($groups);
            $awarded = $sum($groups, $isAwarded);
            $awaiting = $sum($groups, fn ($g) => in_array($g->round_status, ['submitted', ''], true) && !$isAwarded($g) && !in_array($g->status, ['rejected', 'withdrawn'], true));
            $inReview = $sum($groups, fn ($g) => in_array($g->round_status, ['assigned', 'under_review', 'in_review'], true));
            $notSelected = $sum($groups, fn ($g) => $g->round_status === 'not_selected' || $g->status === 'rejected');
            $advanced = (int) $groups->sum('advanced');
            $scored = $appScores->count();
            $matchScored = (int) $groups->sum('match_scored');

            $currencies = $programs->pluck('currency')->filter()->unique()->values();
            $currencyOf = $programs->pluck('currency', 'id');
            $awardedByCurrency = $groups->groupBy(fn ($g) => $currencyOf[$g->program_id] ?? 'KES')
                ->map(fn ($rows, $currency) => ['currency' => $currency, 'amount' => round((float) $rows->sum('awarded_amount'), 2)])
                ->values();

            $summary = [
                'currency' => $currencies->count() === 1 ? $currencies->first() : null,
                'programs' => $programs->count(),
                'rounds' => $rounds->count(),
                'applications' => $submitted,
                'drafts' => $drafts,
                'awaiting_review' => $awaiting,
                'in_review' => $inReview,
                'scored' => $scored,
                'not_scored' => max(0, $submitted - $scored),
                'advanced' => $advanced,
                'awarded' => $awarded,
                'not_selected' => $notSelected,
                'awarded_amount' => $currencies->count() <= 1 ? round((float) $groups->sum('awarded_amount'), 2) : null,
                'awarded_amount_by_currency' => $awardedByCurrency,
                'review_progress' => $submitted ? round($scored / $submitted * 100, 1) : 0,
                'award_rate' => $submitted ? round($awarded / $submitted * 100, 1) : 0,
                'average_score_percent' => $scored ? round($appScores->avg('percent'), 1) : null,
                'average_match_score' => $matchScored ? round((float) $groups->sum('match_sum') / $matchScored, 1) : null,
            ];

            // ── Application statuses ─────────────────────────────────────────────────────────────
            $byStatus = $groups->groupBy('status')->map(fn ($rows) => (int) $rows->sum('total'));
            $statusKeys = collect(self::STATUS_ORDER)->merge($byStatus->keys()->diff(self::STATUS_ORDER))->values();
            $applicationStatuses = $statusKeys->map(fn ($key) => [
                'key' => $key,
                'count' => (int) ($byStatus[$key] ?? 0),
                'percent' => $submitted ? round(($byStatus[$key] ?? 0) / $submitted * 100, 1) : 0,
            ])->values();

            // ── Review health ────────────────────────────────────────────────────────────────────
            $assignments = DB::table('reviewer_application_assignments')
                ->whereIn('application_id', $appIds())
                ->when($roundId, fn ($q) => $q->where('round_id', $roundId))
                ->groupBy('status')->selectRaw('status, COUNT(*) as total')
                ->pluck('total', 'status');
            // ISSUE-39: in a finalized round, a review that never completed counts as "did not run", not as needing a look
            $ai = DB::table('application_ai_reviews as air')
                ->join('program_rounds as prr', 'prr.id', '=', 'air.round_id')
                ->whereIn('air.application_id', $appIds())
                ->when($roundId, fn ($q) => $q->where('air.round_id', $roundId))
                ->selectRaw("CASE WHEN prr.status = 'finalized' AND air.status NOT IN ('ready', 'stale') THEN 'not_run' ELSE air.status END as st, COUNT(*) as total")
                ->groupBy('st')
                ->pluck('total', 'st');
            $aiCount = fn (array $statuses) => (int) $ai->only($statuses)->sum();

            // Coach reviews against each round's minimum number of Business Coaches
            $fullyReviewed = $scoreRows->filter(fn ($r) => (int) $r->human_scores >= max(1, (int) ($roundsById[$r->round_id]->min_reviewers_required ?? 1)))->count();
            $partlyReviewed = $scoreRows->filter(fn ($r) => (int) $r->human_scores > 0 && (int) $r->human_scores < max(1, (int) ($roundsById[$r->round_id]->min_reviewers_required ?? 1)))->count();
            $methods = $rounds->countBy(fn ($r) => $r->review_method ?: 'manual');

            $reviewHealth = [
                'review_methods' => [
                    'manual' => (int) ($methods['manual'] ?? 0),
                    'ai_assisted' => (int) ($methods['ai_assisted'] ?? 0),
                    'ai_only' => (int) ($methods['ai_only'] ?? 0),
                ],
                'assignments' => [
                    'total' => (int) $assignments->sum(),
                    'unassigned' => (int) ($assignments['unassigned'] ?? 0),
                    'assigned' => (int) ($assignments['assigned'] ?? 0),
                    'in_review' => (int) ($assignments['in_review'] ?? 0),
                    'completed' => (int) ($assignments['completed'] ?? 0),
                ],
                'scores' => [
                    'business_coach' => (int) $scoreRows->sum('human_scores'),
                    'ai' => (int) $scoreRows->sum('ai_scores'),
                    'fully_reviewed' => $fullyReviewed,
                    'partly_reviewed' => $partlyReviewed,
                ],
                'ai' => [
                    'total' => (int) $ai->sum(),
                    'working' => $aiCount(self::AI_WORKING),
                    'ready' => $aiCount(['ready']),
                    'needs_attention' => $aiCount(['needs_attention']),
                    'failed' => $aiCount(['failed']),
                    'stale' => $aiCount(['stale']),
                    'not_run' => $aiCount(['not_run']),
                ],
                'awaiting_review' => $awaiting,
                'in_review' => $inReview,
                'scored' => $scored,
                'progress' => $summary['review_progress'],
            ];

            // ── Performance by month (zero-filled, oldest first) ─────────────────────────────────
            $month = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', $submittedAt)" : "DATE_FORMAT($submittedAt, '%Y-%m')";
            $scoredSql = 'CASE WHEN EXISTS (SELECT 1 FROM application_scores s1 WHERE s1.application_id = a.id' . ($roundId ? ' AND s1.round_id = ' . $roundId : '') . ') THEN 1 ELSE 0 END';
            $byMonth = $base()
                ->groupByRaw($month)
                ->selectRaw("$month as month, COUNT(*) as applications, SUM($scoredSql) as scored, SUM($advancedSql) as advanced,
                    SUM(CASE WHEN a.status IN ('awarded','completed') THEN 1 ELSE 0 END) as awarded")
                ->get()->keyBy('month');

            $firstMonth = $from ? $from->startOfMonth() : ($byMonth->keys()->filter()->min() ? CarbonImmutable::createFromFormat('!Y-m', $byMonth->keys()->filter()->min()) : $now->startOfMonth());
            $performance = [];
            for ($m = $firstMonth->startOfMonth(); $m <= $now; $m = $m->addMonthNoOverflow()) {
                $key = $m->format('Y-m');
                $row = $byMonth[$key] ?? null;
                $apps = (int) ($row->applications ?? 0);
                $performance[] = [
                    'month' => $key,
                    'applications' => $apps,
                    'scored' => (int) ($row->scored ?? 0),
                    'advanced' => (int) ($row->advanced ?? 0),
                    'awarded' => (int) ($row->awarded ?? 0),
                    'award_rate' => $apps ? round(($row->awarded ?? 0) / $apps * 100, 1) : 0,
                ];
            }

            // ── Score distribution ───────────────────────────────────────────────────────────────
            $bands = array_fill_keys(array_keys(self::SCORE_BANDS), 0);
            foreach ($appScores as $s) {
                foreach (self::SCORE_BANDS as $band => $min) {
                    if ($s['percent'] >= $min) { $bands[$band]++; break; }
                }
            }
            $scoreDistribution = collect($bands)->map(fn ($count, $band) => ['band' => $band, 'count' => $count])->values()
                ->push(['band' => 'not_scored', 'count' => max(0, $submitted - $scored)]);

            // ── Criteria: each round's own rubric, plus any criterion that only appears in stored scores ──
            $criteria = [];
            foreach ($rounds as $r) {
                if ($roundId && (int) $r->id !== $roundId) continue;
                foreach ((array) ($r->scoring_criteria ?? []) as $c) {
                    $name = trim((string) ($c['name'] ?? ''));
                    if ($name === '') continue;
                    $id = $this->criterionId($r->id, $c, $name);
                    $criteria[$id] = $this->criterionRow($id, $name, $r, $c, $calculator, true);
                }
            }
            DB::table('application_scores')
                ->whereIn('application_id', $appIds())
                ->when($roundId, fn ($q) => $q->where('round_id', $roundId))
                ->select(['id', 'round_id', 'source', 'criterion_scores'])
                ->orderBy('id')
                ->lazy(500)
                ->each(function ($score) use (&$criteria, $roundsById, $calculator) {
                    $r = $roundsById[$score->round_id] ?? null;
                    if (!$r) return;
                    $byName = collect((array) ($r->scoring_criteria ?? []))->keyBy(fn ($c) => trim((string) ($c['name'] ?? '')));
                    foreach ((array) json_decode((string) $score->criterion_scores, true) as $entry) {
                        $name = trim((string) ($entry['criterion'] ?? $entry['criterion_name'] ?? ''));
                        if ($name === '' || !is_numeric($entry['score'] ?? null)) continue;
                        $config = $byName->get($name);
                        $id = $this->criterionId($r->id, $config ?? [], $name);
                        $criteria[$id] ??= $this->criterionRow($id, $name, $r, $config ?? [], $calculator, false);
                        $criteria[$id]['_sum'] += (float) $entry['score'];
                        $criteria[$id]['scores_count']++;
                        $score->source === 'ai' ? $criteria[$id]['ai_scores']++ : $criteria[$id]['business_coach_scores']++;
                    }
                });
            $criteria = collect($criteria)->map(function ($c) {
                $avg = $c['scores_count'] ? $c['_sum'] / $c['scores_count'] : null;
                unset($c['_sum']);
                $c['average_score'] = $avg !== null ? round($avg, 2) : null;
                $c['average_percent'] = $avg !== null && $c['max_score'] ? round(min(100, $avg / $c['max_score'] * 100), 1) : null;
                return $c;
            })->values();

            // ── Programs ─────────────────────────────────────────────────────────────────────────
            $groupsByProgram = $groups->groupBy('program_id');
            $appProgram = $base()->whereIn('a.id', $appScores->keys()->all() ?: [0])->pluck('a.program_id', 'a.id');
            $scoresByProgram = $appScores->groupBy(fn ($s, $appId) => $appProgram[$appId] ?? 0);
            $programRows = $programs->map(function ($p) use ($groupsByProgram, $scoresByProgram, $allRounds, $sum, $isAwarded) {
                $g = collect($groupsByProgram->get($p->id, []));
                $apps = $sum($g);
                $s = collect($scoresByProgram->get($p->id, []));
                $pr = $allRounds->where('program_id', $p->id);
                $current = $pr->first(fn ($r) => in_array($r->status, ['published', 'in_review'], true))
                    ?? $pr->filter(fn ($r) => $r->status !== 'draft')->last() ?? $pr->first();

                return [
                    'id' => (int) $p->id,
                    'title' => $p->program_title,
                    'status' => $p->status,
                    'currency' => $p->currency,
                    'rounds' => $pr->count(),
                    'current_round' => $current ? ['id' => (int) $current->id, 'number' => (int) $current->round_number, 'name' => $current->round_name, 'review_method' => $current->review_method ?: 'manual'] : null,
                    'applications' => $apps,
                    'in_review' => $sum($g, fn ($x) => in_array($x->round_status, ['assigned', 'under_review', 'in_review'], true)),
                    'scored' => $s->count(),
                    'advanced' => (int) $g->sum('advanced'),
                    'awarded' => $sum($g, $isAwarded),
                    'awarded_amount' => round((float) $g->sum('awarded_amount'), 2),
                    'review_progress' => $apps ? round($s->count() / $apps * 100, 1) : 0,
                    'average_score_percent' => $s->count() ? round($s->avg('percent'), 1) : null,
                    'use_match_score' => (bool) $p->use_match_score,
                    'average_match_score' => $p->use_match_score && $g->sum('match_scored') ? round((float) $g->sum('match_sum') / $g->sum('match_scored'), 1) : null,
                ];
            })->values();

            // ── Top applications (highest scored) ────────────────────────────────────────────────
            $topSorted = $appScores->sortByDesc('percent');
            $topIds = ($topLimit === null ? $topSorted : $topSorted->take($topLimit))->keys()->all();
            $topApps = $topIds ? ProgramApplication::query()
                ->whereIn('id', $topIds)
                ->select(['id', 'uid', 'program_id', 'user_id', 'business_id', 'startup_name', 'status', 'round_status', 'current_round_id', 'match_score', 'awarded_amount', 'created_at'])
                ->with('user:id,first_name,last_name,image')
                ->get()->keyBy('id') : collect();
            $submittedDates = $topIds ? DB::table('application_round_history')->whereIn('application_id', $topIds)
                ->groupBy('application_id')->selectRaw('application_id, MIN(submitted_at) as submitted_at')->pluck('submitted_at', 'application_id') : collect();
            $programTitles = $programs->pluck('program_title', 'id');
            $topApplications = collect($topIds)->map(function ($id) use ($topApps, $appScores, $roundsById, $programTitles, $currencyOf, $submittedDates) {
                $app = $topApps->get($id);
                if (!$app) return null;
                $score = $appScores[$id];
                $r = $roundsById[$score['round_id']] ?? null;
                $profile = $app->applicantProfile();
                $submittedOn = $submittedDates[$id] ?? $app->created_at;

                return [
                    'id' => (int) $app->id,
                    'uid' => $app->uid,
                    'applicant_name' => $profile['applicant_name'],
                    'applicant_image' => $profile['applicant_image'],
                    'business_name' => $app->startup_name,
                    'program' => ['id' => (int) $app->program_id, 'title' => $programTitles[$app->program_id] ?? null],
                    'round' => $r ? ['id' => (int) $r->id, 'number' => (int) $r->round_number, 'name' => $r->round_name] : null,
                    'status' => $app->status,
                    'round_status' => $app->round_status,
                    'score' => $score['score'],
                    'max_score' => $score['max_score'],
                    'score_percent' => $score['percent'],
                    'match_score' => $app->match_score !== null ? (float) $app->match_score : null,
                    'awarded_amount' => $app->awarded_amount !== null ? (float) $app->awarded_amount : null,
                    'currency' => $currencyOf[$app->program_id] ?? null,
                    'submitted_at' => $submittedOn ? CarbonImmutable::parse($submittedOn)->toIso8601String() : null,
                ];
            })->filter()->values();

            // ── Program delivery operations ────────────────────────────────────────────────────
            // Selection analytics above explains who moved through review. These aggregates explain what happens
            // after selection: configured rounds, active milestones, M&E checkpoints, site visits and disbursements.
            $countRows = fn (Collection $rows, string $key = 'status') => $rows
                ->mapWithKeys(fn ($row) => [(string) $row->{$key} => (int) $row->total])
                ->all();

            $roundsInView = $roundId ? $rounds->where('id', $roundId) : $rounds;
            $roundStatusCounts = $roundsInView->countBy(fn ($item) => (string) ($item->status ?: 'draft'))
                ->map(fn ($total) => (int) $total)->all();

            $milestoneBase = fn () => DB::table('program_milestones as m')
                ->whereIn('m.app_id', $appIds())
                ->where('m.is_template', false);
            $milestoneStatuses = $milestoneBase()->groupBy('m.status')
                ->selectRaw('m.status, COUNT(*) as total')->get();
            $releaseStatuses = $milestoneBase()->groupBy('m.fund_release_status')
                ->selectRaw('m.fund_release_status, COUNT(*) as total')->get();
            $milestoneTotals = $milestoneBase()->selectRaw("COUNT(*) as total,
                    SUM(CASE WHEN m.status = 'completion_approved' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN m.estimated_completion_date < ? AND m.status NOT IN ('completion_approved','rejected') THEN 1 ELSE 0 END) as overdue,
                    SUM(COALESCE(m.amount, 0)) as planned_amount",
                    [$now->toDateString()])
                ->first();

            $checkpointBase = fn () => DB::table('m_e_checkpoints as c')->whereIn('c.app_id', $appIds());
            $checkpointStatuses = $checkpointBase()->groupBy('c.status')
                ->selectRaw('c.status, COUNT(*) as total')->get();
            $checkpointTotals = $checkpointBase()->selectRaw("COUNT(*) as total,
                    SUM(CASE WHEN c.sent_at IS NOT NULL THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN c.due_date < ? AND c.status NOT IN ('verified') THEN 1 ELSE 0 END) as overdue,
                    SUM(CASE WHEN c.require_site_visit = 1 THEN 1 ELSE 0 END) as requiring_site_visit",
                    [$now->toDateString()])
                ->first();

            $siteVisitRows = DB::table('m_e_site_visits as v')
                ->join('m_e_checkpoints as c', 'c.id', '=', 'v.checkpoint_id')
                ->whereIn('c.app_id', $appIds())
                ->groupBy('v.status')
                ->selectRaw('v.status, COUNT(*) as total')
                ->get();
            $siteVisitTotal = (int) $siteVisitRows->sum('total');
            $siteVisitCompleted = (int) ($siteVisitRows->firstWhere('status', 'completed')->total ?? 0);

            $disbursementRows = DB::table('disbursements as d')
                ->join('program_milestones as m', 'm.id', '=', 'd.milestone_id')
                ->whereIn('m.app_id', $appIds())
                ->groupBy('d.status', 'd.currency')
                ->selectRaw('d.status, d.currency, COUNT(*) as total, SUM(d.amount) as amount,
                    SUM(CASE WHEN d.supplier_confirmed = 1 THEN 1 ELSE 0 END) as supplier_confirmed')
                ->get();
            $moneyByStatus = fn (string $status) => $disbursementRows->where('status', $status)
                ->groupBy('currency')
                ->map(fn ($rows, $currency) => [
                    'currency' => $currency,
                    'amount' => round((float) $rows->sum('amount'), 2),
                ])->values()->all();
            $disbursementStatuses = $disbursementRows->groupBy('status')
                ->map(fn ($rows) => (int) $rows->sum('total'))->all();

            $operations = [
                'rounds' => [
                    'total' => $roundsInView->count(),
                    'by_status' => $roundStatusCounts,
                ],
                'milestones' => [
                    'total' => (int) ($milestoneTotals->total ?? 0),
                    'completed' => (int) ($milestoneTotals->completed ?? 0),
                    'overdue' => (int) ($milestoneTotals->overdue ?? 0),
                    'planned_amount' => round((float) ($milestoneTotals->planned_amount ?? 0), 2),
                    'by_status' => $countRows($milestoneStatuses),
                    'release_by_status' => $countRows($releaseStatuses, 'fund_release_status'),
                ],
                'monitoring' => [
                    'checkpoints' => [
                        'total' => (int) ($checkpointTotals->total ?? 0),
                        'sent' => (int) ($checkpointTotals->sent ?? 0),
                        'overdue' => (int) ($checkpointTotals->overdue ?? 0),
                        'requiring_site_visit' => (int) ($checkpointTotals->requiring_site_visit ?? 0),
                        'by_status' => $countRows($checkpointStatuses),
                    ],
                    'site_visits' => [
                        'total' => $siteVisitTotal,
                        'completed' => $siteVisitCompleted,
                        'completion_rate' => $siteVisitTotal ? round($siteVisitCompleted / $siteVisitTotal * 100, 1) : 0,
                        'by_status' => $countRows($siteVisitRows),
                    ],
                ],
                'disbursements' => [
                    'total' => (int) $disbursementRows->sum('total'),
                    'supplier_confirmed' => (int) $disbursementRows->sum('supplier_confirmed'),
                    'by_status' => $disbursementStatuses,
                    'completed_amount_by_currency' => $moneyByStatus('completed'),
                    'processing_amount_by_currency' => $moneyByStatus('processing'),
                    'pending_amount_by_currency' => $moneyByStatus('pending'),
                ],
                // Drawn as charts: funds out by month, who received them, Business Coach pay, and reported impact
                'delivery' => app(\App\Service\Program\ProgramDeliveryAnalytics::class)->build($appIds(), $programIds, $roundId),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Program analytics fetched successfully.',
                'data' => [
                    'filters' => [
                        'program_id' => $programId,
                        'round_id' => $roundId,
                        'period' => $period,
                        'from' => $from?->toIso8601String(),
                        'to' => $now->toIso8601String(),
                        'options' => [
                            'programs' => $allPrograms->map(fn ($p) => ['id' => (int) $p->id, 'title' => $p->program_title, 'status' => $p->status, 'currency' => $p->currency])->values(),
                            'rounds' => $allRounds->map(fn ($r) => ['id' => (int) $r->id, 'program_id' => (int) $r->program_id, 'number' => (int) $r->round_number, 'name' => $r->round_name, 'status' => $r->status, 'review_method' => $r->review_method ?: 'manual'])->values(),
                            'periods' => self::PERIODS,
                        ],
                    ],
                    'summary' => $summary,
                    'application_statuses' => $applicationStatuses,
                    'review_health' => $reviewHealth,
                    'performance' => $performance,
                    'score_distribution' => $scoreDistribution,
                    'criteria' => $criteria,
                    'programs' => $programRows,
                    'top_applications' => $topApplications,
                    'operations' => $operations,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['user_id' => Auth::id(), 'input' => $request->only(['program_id', 'round_id', 'period', 'top'])]);

            return response()->json(['success' => false, 'message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /** The highest total a round's rubric can give: 100 for weighted and pass/fail, the sum of every criterion's top score for a simple total */
    private function roundMaxTotal(ProgramRound $round, RubricCalculator $calculator): float
    {
        if (($round->rubric_mode ?? 'weighted') !== 'simple_total') return 100.0;
        $max = collect((array) ($round->scoring_criteria ?? []))->sum(fn ($c) => $calculator->maxScoreFor($c));

        return $max > 0 ? (float) $max : 100.0;
    }

    /** A criterion's stable id: its own id when the rubric has one, otherwise the round and its name */
    private function criterionId(int $roundId, array $config, string $name): string
    {
        return !empty($config['id']) ? 'r' . $roundId . ':' . $config['id'] : 'r' . $roundId . ':' . (Str::slug($name) ?: md5($name));
    }

    private function criterionRow(string $id, string $name, ProgramRound $round, array $config, RubricCalculator $calculator, bool $inRubric): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'description' => $config['description'] ?? null,
            'program_id' => (int) $round->program_id,
            'round' => ['id' => (int) $round->id, 'number' => (int) $round->round_number, 'name' => $round->round_name],
            'rubric_mode' => $round->rubric_mode ?: 'weighted',
            'weight' => isset($config['weight']) && is_numeric($config['weight']) ? (float) $config['weight'] : null,
            'max_score' => $config ? $calculator->maxScoreFor($config) : null,
            'in_rubric' => $inRubric,
            'scores_count' => 0,
            'business_coach_scores' => 0,
            'ai_scores' => 0,
            '_sum' => 0.0,
        ];
    }

    /**
     * GET capital/analytics: the capital provider's dashboard (owner and team see the owner's numbers).
     *  offers        {total, live}                 live = visible and open today
     *  applications  {total, pending, accepted, rejected}
     *  capital       {total, available, committed, deployed}  committed = requested by accepted applications,
     *                                                           deployed = milestones released
     *  avg_score, distribution {90-100, 80-89, 70-79, 60-69, <60}, breakdown {sector, geo, stage, revenue, team, impact,
     *  milestones, documents, bonus} (average points), performance_month [{month: "2026-06", label: "Jun",
     *  applications, match (score >= 60), accepted, conversion (% match)}] for the last 6 months, top_startups (10)
     *  The older keys stay: funded (= accepted), total_match (= applications total).
     */
    public function index_capital(){
        try {
            $ownerId = \App\Service\Capital\CapitalAccess::ownerId(Auth::user());
            if ($ownerId === null) {
                return response()->json(['message' => 'Only capital providers have capital analytics.'], 403);
            }

            $offers = \App\Models\Capital\CapitalOffer::where('user_id', $ownerId)->get();
            $live = \App\Models\Capital\CapitalOffer::where('user_id', $ownerId)->openToPublic()->count();
            $pitches = StartupPitches::where('capital_owner_id', $ownerId)->get();
            $accepted = $pitches->where('status', StartupPitches::STATUS_ACCEPTED);
            $count = $pitches->count();

            $deployed = (float) \App\Models\Capital\CapitalMilestone::whereIn('app_id', $pitches->pluck('id'))->where('fund_released', true)->sum('amount');

            $dist = ['90-100' => 0, '80-89' => 0, '70-79' => 0, '60-69' => 0, '<60' => 0];
            $breakKeys = ['sector', 'geo', 'stage', 'revenue', 'team', 'impact', 'milestones', 'documents', 'bonus'];
            $break = array_fill_keys($breakKeys, 0.0);
            foreach ($pitches as $pitch) {
                $score = (float) $pitch->score;
                $bucket = $score >= 90 ? '90-100' : ($score >= 80 ? '80-89' : ($score >= 70 ? '70-79' : ($score >= 60 ? '60-69' : '<60')));
                $dist[$bucket]++;
                foreach (\App\Service\Capital\CapitalMatchScore::parseBreakdown($pitch->score_breakdown) as $key => $value) {
                    if (array_key_exists($key, $break)) {
                        $break[$key] += $value;
                    }
                }
            }
            $breakAvg = array_map(fn ($v) => $count > 0 ? round($v / $count, 2) : 0, $break);

            // The last 6 months, oldest first, every month present (0 when nothing came in)
            $months = [];
            for ($i = 5; $i >= 0; $i--) {
                $m = now()->startOfMonth()->subMonths($i);
                $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'applications' => 0, 'match' => 0, 'accepted' => 0, 'conversion' => 0];
            }
            foreach ($pitches as $pitch) {
                $key = $pitch->created_at?->format('Y-m');
                if (! isset($months[$key])) {
                    continue;
                }
                $months[$key]['applications']++;
                if ((float) $pitch->score >= 60) {
                    $months[$key]['match']++;
                }
                if ((int) $pitch->status === StartupPitches::STATUS_ACCEPTED) {
                    $months[$key]['accepted']++;
                }
            }
            foreach ($months as &$row) {
                $row['conversion'] = $row['applications'] > 0 ? (int) round($row['match'] / $row['applications'] * 100) : 0;
            }
            unset($row);

            $avg = $count > 0 ? round($pitches->avg(fn ($p) => (float) $p->score), 2) : 0;

            return response()->json([
                'offers' => ['total' => $offers->count(), 'live' => $live],
                'applications' => [
                    'total' => $count,
                    'pending' => $pitches->where('status', StartupPitches::STATUS_PENDING)->count(),
                    'accepted' => $accepted->count(),
                    'rejected' => $pitches->where('status', StartupPitches::STATUS_REJECTED)->count(),
                ],
                'capital' => [
                    'total' => round((float) $offers->sum(fn ($o) => (float) $o->total_capital_available), 2),
                    'available' => round((float) $offers->sum(fn ($o) => (float) $o->available_amount), 2),
                    'committed' => round((float) $accepted->sum(fn ($p) => (float) $p->total_amount_requested), 2),
                    'deployed' => round($deployed, 2),
                ],
                'avg_score' => $avg,
                'funded' => $accepted->count(),
                'total_match' => $count,
                'distribution' => $dist,
                'breakdown' => $breakAvg,
                'performance_month' => array_values($months),
                'top_startups' => $pitches->sortByDesc('score')->take(10)->values(),
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }


    public function get_role()
    {
        $user = Auth::user()->load('organizationRole.role');
        $user->role = $user->organizationRole?->role?->name ?? 'super-admin';
        $user->program_owner_id = $user->organizationOwnerId();
        return $user;
    }


}
