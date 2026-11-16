<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Service\Misc\ErrorLogService;
use App\Service\Program\ApplicantPreviews;
use App\Support\ApiContract;
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
    private const TO_REVIEW = ['pending', 'submitted', 'under_review', 'in_review'];

    /** Accepted in any form */
    private const APPROVED = ['approved', 'awarded', 'completed'];

    public function index()
    {
        try {
            $user = Auth::user();
            if ((int) $user->user_type_id !== 4) {
                return response()->json(['message' => 'Only program organizations have an applications overview.'], 403);
            }
            $ownerId = $user->organizationOwnerId();

            // Submitted applications of this program (drafts are not applications yet)
            $submitted = fn ($q) => $q->where('status', '!=', 'draft');

            $programs = Program::query()
                ->where('user_id', $ownerId)
                ->select([
                    'id', 'program_title', 'program_type', 'total_rounds', 'status', 'visible',
                    'use_match_score', 'match_score_limit', 'application_start_date', 'application_deadline',
                    'currency', 'total_program_amount', 'created_at',
                ])
                ->withCount([
                    'applications as applications_count' => $submitted,
                    'applications as to_review_count' => fn ($q) => $q->whereIn('status', self::TO_REVIEW),
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
                    ->select(['id', 'program_id', 'round_number', 'round_name', 'open_date', 'close_date', 'status', 'review_method', 'ai_review_paused_at'])
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
                    'status' => ApiContract::statusMeta('program', $program->status),
                    'visible' => (bool) $program->visible,
                    'use_match_score' => (bool) $program->use_match_score,
                    'match_score_limit' => $min ?: null,
                    'currency' => $program->currency,
                    'total_program_amount' => $program->total_program_amount,
                    'open_date' => $firstRound?->open_date ?? $program->application_start_date,
                    'close_date' => $firstRound?->close_date ?? $program->application_deadline,
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
                        'applications_count' => (int) ($inRound[$round->id] ?? 0),
                    ] : null,
                    // AI review progress in the current round (null when the round is reviewed by people only)
                    'ai_review' => $usesAi ? [
                        'working' => $aiCount(['queued', 'extracting', 'analyzing']),
                        'ready' => $aiCount(['ready']),
                        'needs_attention' => $aiCount(['needs_attention']),
                        'failed' => $aiCount(['failed']),
                        'stale' => $aiCount(['stale']),
                    ] : null,
                ];
            })->values();

            $scored = $rows->sum('scored_count');
            $weightedScore = $rows->sum(fn ($row) => ($row['avg_match_score'] ?? 0) * $row['scored_count']);

            return response()->json([
                'message' => 'Applications overview fetched successfully.',
                'programs' => $rows,
                'totals' => [
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
                ],
            ]);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['user_id' => Auth::id()]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }
}
