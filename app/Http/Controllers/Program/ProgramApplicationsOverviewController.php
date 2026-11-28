<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Service\Misc\ErrorLogService;
use App\Service\Program\ApplicantPreviews;
use App\Support\ApiContract;
use App\Support\CollectionList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * GET programs/applications-overview
 *
 * One summary row per program the signed-in program organization owns (team members see their
 * organization owner's programs), for the owner's Applications page. Every number is counted by the
 * database, so the page no longer downloads every application to add them up in the browser.
 * Drafts are never counted as applications.
 */
class ProgramApplicationsOverviewController extends Controller
{
    /** Waiting for a decision */

    /** Quick ranges for a deadline, which can lie on either side of today */
    private const PAST_AND_FUTURE = ['today', 'next7Days', 'next30Days', 'yesterday', 'last7Days', 'last30Days', 'thisMonth', 'lastMonth', 'thisYear'];

    /** Accepted in any form */
    private const APPROVED = ['approved', 'awarded', 'in_monitoring', 'completed'];

    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            if ((int) $user->user_type_id !== 4) {
                return response()->json(['message' => 'Only program organizations have an applications overview.'], 403);
            }
            $ownerId = $user->organizationOwnerId();

            // Submitted applications of this program (drafts are not applications yet)
            $submitted = fn ($q) => $q->where('status', '!=', 'draft');

            // ISSUE-38: every program of the organization, whoever on the team created it
            $programs = Program::query()
                ->ofOrganization($user)
                ->select([
                    'id', 'program_title', 'program_type', 'total_rounds', 'status', 'visible',
                    'use_match_score', 'match_score_limit', 'application_start_date', 'application_deadline',
                    'application_opens_at', 'application_closes_at', 'timezone',
                    'currency', 'total_program_amount', 'created_at',
                ])
                ->withCount([
                    'applications as applications_count' => $submitted,
                    // ISSUE-41: still waiting for a decision in its current round (not decided, not in a finalized round)
                    'applications as to_review_count' => fn ($q) => $q
                        ->whereIn('round_status', ['submitted', 'assigned', 'under_review', 'scored'])
                        ->whereNotIn('status', ['draft', 'rejected', 'withdrawn', 'awarded', 'in_monitoring', 'completed'])
                        ->whereNotIn('current_round_id', \App\Models\Programs\Rounds\ProgramRound::where('status', 'finalized')->select('id')),
                    'applications as approved_count' => fn ($q) => $q->whereIn('status', self::APPROVED),
                    'applications as rejected_count' => fn ($q) => $q->where('status', 'rejected'),
                    'applications as draft_count' => fn ($q) => $q->where('status', 'draft'),
                    'applications as scored_count' => fn ($q) => $submitted($q)->whereNotNull('match_score'),
                ])
                ->withAvg(['applications as avg_match_score' => fn ($q) => $submitted($q)->whereNotNull('match_score')], 'match_score')
                ->withMax(['applications as last_applied_at' => $submitted], 'created_at')
                // How many submitted applications reach the program's own minimum match score
                ->addSelect(['above_min_count' => ProgramApplication::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('program_applications.program_id', 'programs.id')
                    ->where('program_applications.status', '!=', 'draft')
                    ->whereNotNull('program_applications.match_score')
                    ->whereColumn('program_applications.match_score', '>=', 'programs.match_score_limit'),
                ])
                ->with(['rounds' => fn ($q) => $q
                    ->select(['id', 'program_id', 'round_number', 'round_name', 'open_date', 'close_date', 'opens_at', 'closes_at', 'status', 'review_method', 'ai_review_paused_at'])
                    ->orderBy('round_number')])
                ->latest()
                ->get();

            ApplicantPreviews::attach($programs);

            // The round each program is on: the first one taking or reviewing applications,
            // otherwise the latest one that has started (or the first round of a draft program)
            $currentRounds = $programs->mapWithKeys(function (Program $program) {
                $rounds = $program->rounds;
                $active = $rounds->first(fn ($r) => in_array($r->status, ['published', 'in_review'], true))
                    ?? $rounds->filter(fn ($r) => $r->status !== 'draft')->last()
                    ?? $rounds->first();

                return [$program->id => $active];
            });
            $roundIds = $currentRounds->filter()->pluck('id')->values();

            // Applications sitting in each current round
            $inRound = $roundIds->isEmpty() ? collect() : DB::table('program_applications')
                ->whereIn('current_round_id', $roundIds)
                ->where('status', '!=', 'draft')
                ->groupBy('current_round_id')
                ->selectRaw('current_round_id, count(*) as total')
                ->pluck('total', 'current_round_id');

            // AI review progress in each current round, by status
            $aiByRound = $roundIds->isEmpty() ? collect() : DB::table('application_ai_reviews')
                ->whereIn('round_id', $roundIds)
                ->groupBy('round_id', 'status')
                ->selectRaw('round_id, status, count(*) as total')
                ->get()
                ->groupBy('round_id');

            $rows = $programs->map(function (Program $program) use ($currentRounds, $inRound, $aiByRound): array {
                $firstRound = $program->rounds->first();
                $round = $currentRounds->get($program->id);
                $usesAi = $round && in_array($round->review_method, ['ai_assisted', 'ai_only'], true);
                $ai = $round ? collect($aiByRound->get($round->id, []))->pluck('total', 'status') : collect();
                $aiCount = fn (array $statuses) => (int) $ai->only($statuses)->sum();
                $min = (int) ($program->match_score_limit ?? 0);

                return [
                    'id' => $program->id,
                    'program_title' => $program->program_title,
                    'program_type' => $program->program_type,
                    'total_rounds' => (int) ($program->total_rounds ?: max(1, $program->rounds->count())),
                    'status' => ApiContract::statusMeta('program', self::effectiveStatus($program)),
                    'visible' => (bool) $program->visible,
                    'use_match_score' => (bool) $program->use_match_score,
                    'match_score_limit' => $min ?: null,
                    'currency' => $program->currency,
                    'total_program_amount' => $program->total_program_amount,
                    // The current round's window (ISSUE-41): a multi-round program's deadline is the round it is on, as the
                    // status and "Current round" are, not round 1's; the program's own dates when it has no round yet
                    'open_date' => ($round ?? $firstRound)?->open_date ?? $program->application_start_date,
                    'close_date' => ($round ?? $firstRound)?->close_date ?? $program->application_deadline,
                    // Exact moments (ISO 8601 with offset) for the countdown
                    'opens_at' => (($round ?? $firstRound)?->opensAt() ?? $program->opensAt())?->format(\App\Support\ProgramSchedule::JSON_FORMAT),
                    'closes_at' => (($round ?? $firstRound)?->closesAt() ?? $program->closesAt())?->format(\App\Support\ProgramSchedule::JSON_FORMAT),
                    'applications_count' => (int) $program->applications_count,
                    'to_review_count' => (int) $program->to_review_count,
                    'approved_count' => (int) $program->approved_count,
                    'rejected_count' => (int) $program->rejected_count,
                    'draft_count' => (int) $program->draft_count,
                    'scored_count' => (int) $program->scored_count,
                    'avg_match_score' => $program->avg_match_score !== null ? round((float) $program->avg_match_score, 1) : null,
                    // Only meaningful when the program sets a minimum
                    'above_min_count' => $min ? (int) $program->above_min_count : null,
                    'last_applied_at' => $program->last_applied_at,
                    'applicant_previews' => $program->applicant_previews ?? [],
                    'current_round' => $round ? [
                        'id' => $round->id,
                        'number' => (int) $round->round_number,
                        'name' => $round->round_name,
                        'status' => $round->status,
                        // manual | ai_assisted | ai_only
                        'review_method' => $round->review_method ?: 'manual',
                        'ai_paused' => (bool) $round->ai_review_paused_at,
                        'open_date' => $round->open_date,
                        'close_date' => $round->close_date,
                        'opens_at' => $round->opensAt()?->format(\App\Support\ProgramSchedule::JSON_FORMAT),
                        'closes_at' => $round->closesAt()?->format(\App\Support\ProgramSchedule::JSON_FORMAT),
                        'applications_count' => (int) ($inRound[$round->id] ?? 0),
                    ] : null,
                    // AI review progress in the current round (null when the round is reviewed by people only)
                    // ISSUE-39: in a finalized round nothing is waiting or needs a look; what never completed did not run
                    'ai_review' => $usesAi ? ($round->status === 'finalized' ? [
                        'working' => 0, 'ready' => $aiCount(['ready']), 'needs_attention' => 0, 'failed' => 0, 'stale' => $aiCount(['stale']),
                        'not_run' => $aiCount(['queued', 'extracting', 'analyzing', 'needs_attention', 'failed']),
                    ] : [
                        'working' => $aiCount(['queued', 'extracting', 'analyzing']),
                        'ready' => $aiCount(['ready']),
                        'needs_attention' => $aiCount(['needs_attention']),
                        'failed' => $aiCount(['failed']),
                        'stale' => $aiCount(['stale']),
                        'not_run' => 0,
                    ]) : null,
                ];
            })->values();

            $scored = $rows->sum('scored_count');
            $weightedScore = $rows->sum(fn ($row) => ($row['avg_match_score'] ?? 0) * $row['scored_count']);

            $totals = [
                'programs' => $rows->count(),
                'open_programs' => $rows->filter(fn ($row) => in_array($row['status']['value'], ['open', 'published'], true))->count(),
                'programs_without_applications' => $rows->where('applications_count', 0)->count(),
                'applications' => $rows->sum('applications_count'),
                'to_review' => $rows->sum('to_review_count'),
                'approved' => $rows->sum('approved_count'),
                'rejected' => $rows->sum('rejected_count'),
                'scored' => $scored,
                'avg_match_score' => $scored ? round($weightedScore / $scored, 1) : null,
                'above_min' => $rows->sum(fn ($row) => $row['above_min_count'] ?? 0),
                'ai_working' => $rows->sum(fn ($row) => $row['ai_review']['working'] ?? 0),
                'ai_needs_attention' => $rows->sum(fn ($row) => ($row['ai_review']['needs_attention'] ?? 0) + ($row['ai_review']['failed'] ?? 0)),
                'ai_programs' => $rows->filter(fn ($row) => $row['ai_review'] !== null)->count(),
            ];

            if (self::listMode($request)) {
                return $this->listResponse($request, $rows, $totals);
            }

            return response()->json([
                'message' => 'Applications overview fetched successfully.',
                'programs' => $rows,
                'totals' => $totals,
            ]);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['user_id' => Auth::id()]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    private static function listMode(Request $request): bool
    {
        return ApiContract::wantsPagination($request) || $request->boolean('export')
            || $request->filled('search') || $request->filled('filters') || $request->filled('sort');
    }

    /** Where a program's reviewing stands: none (no applications), needs (some wait for a decision) or done. */
    private static function reviewOf(array $row): string
    {
        return ! $row['applications_count'] ? 'none' : ($row['to_review_count'] ? 'needs' : 'done');
    }

    /** What the AI is doing in the current round now (null when people review it). */
    private static function aiStateOf(array $row): ?string
    {
        $ai = $row['ai_review'];
        if (! $ai) {
            return null;
        }
        if ($row['current_round']['ai_paused'] ?? false) {
            return 'paused';
        }

        return $ai['working'] ? 'working' : (($ai['needs_attention'] || $ai['failed']) ? 'attention' : ($ai['ready'] ? 'ready' : 'idle'));
    }

    /**
     * The table's page: search, filters, sort, page and export over the summary rows (one per program, so they are
     * few and already counted by the database). `totals` stays the whole account's, whatever the search.
     */
    private function listResponse(Request $request, $rows, array $totals)
    {
        // Described filters (the Applications page builds its panel, chips and URL state from `filter_schema`); the old
        // keys and values are unchanged, so saved links keep working
        $list = CollectionList::for($rows, $request)
            ->search(fn ($r) => (string) $r['program_title'])
            ->filter('id', fn ($r) => $r['id'])
            ->options('review', fn ($r) => self::reviewOf($r), label: 'app:reviewColumn', values: ['needs', 'done', 'none'],
                optionLabels: ['needs' => 'app:needsReview', 'done' => 'app:allReviewed', 'none' => 'app:noApplications'])
            ->options('status', fn ($r) => strtolower((string) $r['status']['value']), label: 'app:status')
            ->options('type', fn ($r) => $r['program_type'] === 'multi_round' ? 'multi' : 'single', label: 'app:type', values: ['single', 'multi'],
                optionLabels: ['single' => 'app:singleRound', 'multi' => 'app:multiRound'])
            ->options('method', fn ($r) => $r['current_round']['review_method'] ?? 'manual', label: 'app:reviewMethod', values: ['manual', 'ai_assisted', 'ai_only'],
                optionLabels: ['manual' => 'app:methodManual', 'ai_assisted' => 'app:methodAiAssisted', 'ai_only' => 'app:methodAiOnly'])
            ->options('ai', fn ($r) => in_array(self::aiStateOf($r), ['working', 'attention', 'paused'], true) ? self::aiStateOf($r) : null, label: 'app:aiStatus',
                values: ['working', 'attention', 'paused'], optionLabels: ['working' => 'app:aiInProgress', 'attention' => 'app:aiNeedsLook', 'paused' => 'app:aiPaused'])
            ->options('match', fn ($r) => $r['use_match_score'] ? 'on' : 'off', label: 'app:matchScore', values: ['on', 'off'],
                optionLabels: ['on' => 'app:matchScoreOn', 'off' => 'app:matchScoreOff'])
            ->options('currency', fn ($r) => $r['currency'] ? strtoupper((string) $r['currency']) : null, label: 'app:currency')
            ->numberRange('applications', fn ($r) => $r['applications_count'], label: 'app:applicationsColumn')
            ->numberRange('to_review', fn ($r) => $r['to_review_count'], label: 'app:exportToReview')
            ->numberRange('score', fn ($r) => $r['avg_match_score'], label: 'app:exportAverageMatchScore')
            ->numberRange('funding', fn ($r) => $r['total_program_amount'] !== null ? (float) $r['total_program_amount'] : null, label: 'app:funding')
            ->numberRange('rounds', fn ($r) => $r['total_rounds'], label: 'app:rounds')
            ->dateRange('deadline', fn ($r) => (string) $r['close_date'], label: 'app:deadlineColumn', presets: self::PAST_AND_FUTURE)
            ->dateRange('last_applied', fn ($r) => (string) $r['last_applied_at'], label: 'app:listFilterLastApplication')
            ->boolean('has_applications', fn ($r) => $r['applications_count'] > 0, label: 'app:listFilterHasApplications')
            ->boolean('has_drafts', fn ($r) => $r['draft_count'] > 0, label: 'app:listFilterHasDrafts')
            ->boolean('visible', fn ($r) => $r['visible'], label: 'app:visibleToApplicants')
            ->sortable([
                'title' => fn ($r) => (string) $r['program_title'],
                'round' => fn ($r) => $r['current_round']['number'] ?? 0,
                'applications' => fn ($r) => $r['applications_count'],
                'to_review' => fn ($r) => $r['to_review_count'],
                'match' => fn ($r) => $r['avg_match_score'] ?? -1,
                'deadline' => fn ($r) => (string) $r['close_date'],
            ]);

        [$items, $page] = $list->fetch();

        $data = [
            'items' => $items,
            'facets' => $list->facets(['review', 'status', 'type', 'method', 'ai', 'match']),
            'filter_schema' => $list->filterSchema(),
            'stats' => $totals,
            'export_truncated' => $list->wantsExport() ? $list->exportTruncated() : false,
        ];
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }

        return response()->json([
            'success' => true,
            'message' => 'Applications overview fetched successfully.',
            'data' => $data,
            'programs' => $items,
            'totals' => $totals,
        ]);
    }

    /**
     * ISSUE-41: one status per program from its real state, by the same moments its deadlines use: open while a round
     * takes applications, under review once none does, then awarded (someone was awarded) or closed when every round
     * is finalized. A draft, cancelled, awarded or completed program keeps its own status.
     */
    public static function effectiveStatus(Program $program): string
    {
        if (in_array($program->status, ['draft', 'cancelled', 'awarded', 'completed', 'closed'], true)) return $program->status;
        $rounds = $program->rounds;
        $now = now();
        $taking = $rounds->contains(fn ($r) => $r->status === 'published'
            && (! $r->opensAt() || $r->opensAt()->lte($now)) && (! $r->closesAt() || $r->closesAt()->gt($now)));
        if ($taking) return 'open';

        $planned = (int) ($program->total_rounds ?: max(1, $rounds->count()));
        $allFinalized = $rounds->isNotEmpty() && $rounds->count() >= $planned && $rounds->every(fn ($r) => $r->status === 'finalized');
        if ($allFinalized) {
            return \App\Models\Programs\ProgramApplication::where('program_id', $program->id)->whereIn('status', ['awarded', 'in_monitoring', 'completed'])->exists()
                ? 'awarded' : 'closed';
        }

        return 'under_review';
    }
}
