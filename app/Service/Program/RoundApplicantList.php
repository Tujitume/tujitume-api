<?php

namespace App\Service\Program;

use App\Models\ApplicationRoundHistory;
use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Models\ReviewerOrder;
use App\Support\ApiContract;
use App\Support\CollectionList;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The applicants of a round as the round's table shows them, and the list language (search / filters / sort / page /
 * export, see App\Support\CollectionList) over them.
 *
 * A row is worked out from several tables (this round's history, scores, Business Coach assignments and AI review), so
 * the list is searched and filtered in memory; one round's field is bounded. The groups the filters use are the same
 * ones the frontend counts by (round-applicants/components/roundListData.js and applicantsTableStatus.js): keep them in
 * step.
 */
class RoundApplicantList
{
    private const PLACEHOLDERS = ['not specified', 'not provided', 'not available', 'unspecified', 'n/a', 'na', 'none', 'null', 'undefined', '-', '—'];

    /** True when the request asks for the paged / searchable form (no list parameters = the old full response). */
    public static function wanted(Request $request): bool
    {
        return ApiContract::wantsPagination($request) || $request->boolean('export')
            || $request->filled('search') || $request->filled('filters') || $request->filled('sort');
    }

    /**
     * Every applicant who took part in the round, shaped for the table, and the round's counts.
     *
     * @return array{0: Collection, 1: array}
     */
    public static function build(ProgramRound $round): array
    {
        $round_id = $round->id;

        // Everyone who took part in this round: those still in it, and those already moved on or out. Accepting an
        // applicant in a manual round moves them to the next round straight away, so the round's own record is needed.
        $appIds = ApplicationRoundHistory::where('round_id', $round_id)->pluck('application_id')
            ->merge(ProgramApplication::where('current_round_id', $round_id)->pluck('id'))
            ->unique()
            ->values();

        $applications = ProgramApplication::with(['reviewer', 'user:id,first_name,last_name,image'])->whereIn('id', $appIds)->get();

        // Stats always from history (accurate regardless of round state)
        $history = ApplicationRoundHistory::where('round_id', $round_id)->get();

        // A row still "in progress" for an applicant who has moved on is an advance that was never closed in the history
        $leftRound = fn ($row) => $row->outcome === 'in_progress'
            && $applications->firstWhere('id', $row->application_id)?->current_round_id !== (int) $round_id;
        $history->each(fn ($row) => $leftRound($row) ? $row->outcome = 'advanced' : null);

        // The round's Business Coach fees, for the "Pay business coaches" button (ISSUE-40)
        $orders = ReviewerOrder::where('round_id', $round->id)->where('order_type', 'round_review')
            ->where('acceptance_status', 'accepted')->where('fee_amount', '>', 0)->get(['work_status', 'payment_status', 'fee_amount']);
        $toPay = $orders->where('work_status', 'approved')->whereIn('payment_status', ['unpaid', 'failed']);
        $reviewerPay = [
            'to_pay_count' => $toPay->count(),
            'to_pay_total' => round((float) $toPay->sum('fee_amount'), 2),
            'sent_count' => $orders->whereIn('payment_status', ['pending', 'leg1_processing'])->count(),
            'paid_count' => $orders->where('payment_status', 'completed')->count(),
            'awaiting_approval_count' => $orders->where('work_status', '!=', 'approved')->whereIn('payment_status', ['unpaid', 'failed'])->count(),
            'currency' => $round->program?->currency ?: config('app.currency', 'KES'),
        ];

        $stats = [
            'total'              => $applications->count(),
            'advanced_count'     => $history->where('outcome', 'advanced')->count(),
            'not_selected_count' => $history->where('outcome', 'not_selected')->count(),
            'awarded_count'      => $history->where('outcome', 'awarded')->count(),
            'in_progress_count'  => $history->where('outcome', 'in_progress')->count(),
            // Reviewer fees of a finalized round that have not been started yet (drives the fallback "Start reviewer payments" button)
            // ISSUE-40: only fees ready to send (work approved, not sent yet, or a failed send); a "pending" fee is
            // already on its way
            'reviewer_payments_to_start' => $round->status === 'finalized' ? (int) ($reviewerPay['to_pay_count'] ?? 0) : 0,
            'reviewer_pay' => $reviewerPay,
        ];

        $scoresByApplication = ApplicationScore::where('round_id', $round_id)
            ->whereIn('application_id', $applications->pluck('id'))
            ->get()
            ->groupBy('application_id');

        // Who is reviewing each application in this round (shown in the "Reviewed By" column)
        $assignmentsByApplication = ReviewerApplicationAssignment::with('reviewer:id,first_name,last_name,image')
            ->where('round_id', $round_id)
            ->whereIn('application_id', $applications->pluck('id'))
            ->get()
            ->groupBy('application_id');

        $historyByApplication = $history->keyBy('application_id');

        // Where the AI is with each application (its newest review), so the table can say "AI suggestion 57" or "AI needs attention" and not just "Not scored"
        $aiByApplication = collect();
        if ($round->usesAi()) {
            $latestAi = ApplicationAiReview::where('round_id', $round_id)->where('status', '!=', 'stale')
                ->whereIn('application_id', $applications->pluck('id'))->selectRaw('MAX(id) as id')->groupBy('application_id');
            $aiByApplication = ApplicationAiReview::whereIn('id', $latestAi)->get(['id', 'application_id', 'status', 'suggested_total'])->keyBy('application_id');
        }

        $mapped = $applications->map(function ($app) use ($round_id, $round, $scoresByApplication, $assignmentsByApplication, $historyByApplication, $aiByApplication) {
            // Get this app's outcome in this specific round from history
            $roundHistory = $historyByApplication->get($app->id);

            // "Scored" has to be backed by this round's own scores. An application row that still says scored, with a
            // score left over from an earlier round and nothing scored here, is not scored in this round (read-only fix
            // for the response: nothing is saved).
            if ((int) $app->current_round_id === (int) $round_id
                && $app->round_status === 'scored'
                && $scoresByApplication->get($app->id, collect())->isEmpty()) {
                $app->round_status = ($roundHistory?->submitted_at || (int) $round->round_number === 1) ? 'submitted' : 'draft';
                $app->average_score = null;
                $app->is_eligible_to_advance = false;
            }

            // Advanced into this round but nothing handed in yet: no scores or answers to show, and why
            $participation = RoundParticipation::for($app, $round, $roundHistory);

            $roundScores = $participation['submitted'] ? $scoresByApplication->get($app->id, collect()) : collect();
            $criteriaCount = is_array($round->scoring_criteria) ? count($round->scoring_criteria) : 0;
            // Human scores take precedence; AI scores only count while nobody has scored
            $humanScores = $roundScores->filter(fn ($score) => ($score->source ?? 'human') === 'human');
            $average = $roundScores->isNotEmpty() ? round((float) ($humanScores->isNotEmpty() ? $humanScores : $roundScores)->avg('total_score'), 1) : null;

            $assignments = $assignmentsByApplication->get($app->id, collect());

            return [
                ...$app->toArray(),
                ...$app->applicantProfile(),
                'reviewers' => $assignments->map(fn ($assignment) => [
                    'user_id' => $assignment->reviewer_id,
                    'name' => trim(($assignment->reviewer?->first_name ?? '') . ' ' . ($assignment->reviewer?->last_name ?? '')),
                    'image' => $assignment->reviewer?->image,
                    'status' => $assignment->status,
                ])->values(),
                'assigned_reviewers_count' => $assignments->count(),
                'completed_reviews_count' => $roundScores->pluck('reviewer_id')->unique()->count(),
                // What the reviewers said, ready to show in the table (sortable, and good for deciding a manual round)
                'review_summary' => [
                    'reviewers_scored' => $roundScores->count(),
                    'average_score' => $average,
                    'rubric_mode' => $round->rubric_mode,
                    'criteria_count' => $criteriaCount,
                    'passed' => ($round->rubric_mode === 'pass_fail' && $average !== null && $criteriaCount > 0)
                        ? (int) round(($average / 100) * $criteriaCount)
                        : null,
                ],
                'participation' => $participation,
                'ai_review' => ($ai = $aiByApplication->get($app->id))
                    ? ['status' => self::aiStatusIn($round, $ai->status), 'suggested_total' => $ai->suggested_total !== null ? (float) $ai->suggested_total : null]
                    : ($round->status === 'finalized' && $round->usesAi() ? ['status' => 'not_run', 'suggested_total' => null] : null),
                'round_outcome' => $roundHistory?->outcome,      // ← what happened in THIS round
                'round_score'   => $roundHistory?->average_score, // ← score in THIS round
                'status' => \App\Support\ApiContract::statusMeta('program_application', $app->status),
                'round_status' => \App\Support\ApiContract::statusMeta('program_application_round', $app->round_status),
                'knockout_status' => \App\Support\ApiContract::statusMeta('knockout', $app->knockout_status),
                'funding_setup_status' => \App\Support\ApiContract::statusMeta('funding_setup', $app->funding_setup_status),
            ];
        });

        return [$mapped, $stats + self::cardCounts($mapped)];
    }

    /**
     * The round page's cards, counted over every applicant here so the page does not need the whole round: the same
     * definitions as the page's own counts did (an applicant still to be decided is "pending", also while awaiting).
     */
    public static function cardCounts(Collection $rows): array
    {
        $awaiting = $rows->filter(fn ($a) => self::awaiting($a));
        $pending = $rows->filter(fn ($a) => self::decision($a) === 'pending');
        $coach = $rows->countBy(fn ($a) => self::coachKey($a) ?? '');
        $ai = $rows->countBy(fn ($a) => self::aiKey($a['ai_review']['status'] ?? null));

        return [
            'submitted' => $rows->count() - $awaiting->count(),
            'awaiting' => $awaiting->count(),
            'pending' => $pending->count(),
            'scored' => $rows->filter(fn ($a) => ! self::awaiting($a) && self::scored($a))->count(),
            'advanced' => $rows->filter(fn ($a) => self::decision($a) === 'advanced')->count(),
            'not_selected' => $rows->filter(fn ($a) => self::decision($a) === 'not_selected')->count(),
            'eligible' => $pending->filter(fn ($a) => self::eligible($a))->count(),
            'coaches_done' => (int) ($coach['done'] ?? 0),
            'coaches_waiting' => (int) ($coach['in_progress'] ?? 0),
            'no_coach' => (int) ($coach['none'] ?? 0),
            'coaches' => $rows->flatMap(fn ($a) => collect($a['reviewers'] ?? [])->pluck('user_id'))->filter()->unique()->count(),
            'ai_ready' => (int) ($ai['ready'] ?? 0),
            'ai_attention' => (int) ($ai['attention'] ?? 0),
            'ai_waiting' => $pending->filter(fn ($a) => ! self::awaiting($a) && in_array(self::aiKey($a['ai_review']['status'] ?? null), ['waiting', 'working', 'none'], true))->count(),
            'ai_tracked' => $rows->filter(fn ($a) => ! empty($a['ai_review']))->count(),
        ];
    }

    public static function eligible(array $a): bool
    {
        return in_array($a['is_eligible_to_advance'] ?? null, [true, 1, '1'], true);
    }

    /**
     * The score a "decide by score" line reads: criteria passed in a pass / fail round, otherwise this round's average
     * (null when nobody has scored it).
     */
    public static function lineScore(array $a, bool $passFail): ?float
    {
        if ($passFail) {
            $passed = $a['review_summary']['passed'] ?? null;

            return $passed === null ? null : (float) $passed;
        }

        return self::score($a);
    }

    /**
     * Who a score line would decide: applicants still to be decided who have submitted, at or above the line (accept)
     * or at or below it (reject), highest / lowest first; and how many are waiting with no score yet.
     *
     * @return array{waiting: int, scored: int, unscored: int, rows: Collection}
     */
    public static function scoreLine(Collection $rows, ProgramRound $round, string $mode, ?float $score): array
    {
        $passFail = $round->rubric_mode === 'pass_fail';
        $waiting = $rows->filter(fn ($a) => self::decision($a) === 'pending' && ! self::awaiting($a))->values();
        $scored = $waiting->filter(fn ($a) => self::lineScore($a, $passFail) !== null)->values();
        $picked = $score === null ? collect() : $scored
            ->filter(fn ($a) => $mode === 'accept' ? self::lineScore($a, $passFail) >= $score : self::lineScore($a, $passFail) <= $score)
            ->sortBy(fn ($a) => self::lineScore($a, $passFail), SORT_REGULAR, $mode === 'accept')
            ->values();

        return ['waiting' => $waiting->count(), 'scored' => $scored->count(), 'unscored' => $waiting->count() - $scored->count(), 'rows' => $picked];
    }

    // ── what each row is grouped by (mirrors roundListData.js / applicantsTableStatus.js) ───────────────────

    private static function key($value): string
    {
        return strtolower((string) (is_array($value) ? ($value['value'] ?? '') : ($value ?? '')));
    }

    /** The value as text, or '' when it is empty or only a placeholder ("Not specified" …). */
    public static function clean($value): string
    {
        $text = trim((string) ($value ?? ''));

        return in_array(mb_strtolower($text), self::PLACEHOLDERS, true) ? '' : $text;
    }

    public static function decision(array $a): string
    {
        $outcome = strtolower((string) ($a['round_outcome'] ?? ''));
        if ($outcome === 'advanced' || $outcome === 'awarded') {
            return 'advanced';
        }
        if ($outcome === 'not_selected') {
            return 'not_selected';
        }
        $status = self::key($a['round_status'] ?? null);
        if ($status === 'advanced') {
            return 'advanced';
        }
        if ($status === 'not_selected' || $status === 'ineligible') {
            return 'not_selected';
        }

        return 'pending';
    }

    public static function awaiting(array $a): bool
    {
        return isset($a['participation']) && ($a['participation']['submitted'] ?? null) === false;
    }

    public static function scored(array $a): bool
    {
        if ((int) ($a['review_summary']['reviewers_scored'] ?? 0) > 0) {
            return true;
        }
        if (self::key($a['round_status'] ?? null) === 'scored') {
            return true;
        }
        $value = $a['round_score'] ?? $a['average_score'] ?? null;

        return $value !== null && (! is_numeric($value) || (float) $value != 0);
    }

    public static function score(array $a): ?float
    {
        if (self::awaiting($a)) {
            return null;
        }
        $raw = $a['review_summary']['average_score'] ?? $a['round_score'] ?? $a['average_score'] ?? null;
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return null;
        }
        $n = (float) $raw;

        return $n == 0 && ! self::scored($a) ? null : $n;
    }

    public static function band(?float $score): ?string
    {
        if ($score === null) {
            return null;
        }

        return $score >= 70 ? 'high' : ($score >= 40 ? 'mid' : 'low');
    }

    /** pending | awaiting | advanced | not_selected */
    public static function statusKey(array $a): string
    {
        $decision = self::decision($a);
        if ($decision !== 'pending') {
            return $decision;
        }

        return self::awaiting($a) ? 'awaiting' : 'pending';
    }

    public static function reviewKey(array $a): ?string
    {
        return self::awaiting($a) ? null : (self::scored($a) ? 'scored' : 'not_scored');
    }

    public static function coachKey(array $a): ?string
    {
        if (self::awaiting($a)) {
            return null;
        }
        $assigned = (int) ($a['assigned_reviewers_count'] ?? 0) ?: count($a['reviewers'] ?? []);
        $done = (int) ($a['completed_reviews_count'] ?? 0);
        if (! $assigned) {
            return 'none';
        }

        return $done >= $assigned ? 'done' : 'in_progress';
    }

    /**
     * ISSUE-39: once a round is finalized nothing more can happen, so a review that never completed (queued, working,
     * needs attention, failed) is shown as "AI review didn't run" (not_run, neutral) and asks nobody for action.
     */
    public static function aiStatusIn(ProgramRound $round, ?string $status): ?string
    {
        return $round->status === 'finalized' && $status !== 'ready' ? 'not_run' : $status;
    }

    /** waiting | working | ready | attention | not_run | none */
    public static function aiKey(?string $status): string
    {
        return match ($status) {
            'queued' => 'waiting',
            'extracting', 'analyzing' => 'working',
            'ready' => 'ready',
            'needs_attention', 'failed' => 'attention',
            'not_run' => 'not_run',
            default => 'none',
        };
    }

    public static function knockoutKey(array $a): ?string
    {
        $k = self::key($a['knockout_status'] ?? null);

        return $k === 'passed' || $k === 'failed' ? $k : null;
    }

    public static function name(array $a): string
    {
        return ($a['applicant_name'] ?? null) ?: (($a['startup_name'] ?? null) ?: (($a['contact_person_name'] ?? null) ?: '#'.$a['id']));
    }

    private static function coachNames(array $a): string
    {
        return collect($a['reviewers'] ?? [])->pluck('name')->filter()->implode(', ');
    }

    /** When they handed this round in (from round 2 on), or when they applied, as Y-m-d H:i:s text for sorting. */
    private static function submittedAt(array $a): string
    {
        $p = $a['participation'] ?? null;
        $value = $p && (int) ($p['round_number'] ?? 0) > 1 ? ($p['submitted_at'] ?? null) : (($a['submitted_at'] ?? null) ?: ($a['created_at'] ?? null));

        return $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i:s') : '';
    }

    /**
     * The round's table: search / filter / sort / page / export over the built rows. The filters are described for the
     * page's panel (`filter_schema`); those that do not apply to the round (Business Coaches in an AI-only or owner-only
     * round, AI in a people-only round, eligibility in a manual round) are still accepted but not offered.
     */
    public static function list(Collection $rows, Request $request, ?ProgramRound $round = null): CollectionList
    {
        $list = CollectionList::for($rows, $request)
            ->search(fn ($a) => implode(' ', [
                self::name($a), $a['startup_name'] ?? '', $a['contact_person_name'] ?? '', $a['contact_person_email'] ?? '',
                self::coachNames($a), self::clean($a['sector'] ?? null),
            ]))
            ->filter('id', fn ($a) => $a['id'])
            ->options('status', fn ($a) => self::statusKey($a), label: 'app:status', values: ['pending', 'awaiting', 'advanced', 'not_selected'])
            ->options('review', fn ($a) => self::reviewKey($a), label: 'grant:roundApplicants.listUi.filterScore', fallback: 'Score', values: ['scored', 'not_scored']);

        $coaches = ! $round || ($round->review_method !== 'ai_only' && ($round->assignment_type ?: 'owner_only') !== 'owner_only');
        $ai = ! $round || $round->usesAi();
        $coaches
            ? $list->options('coach', fn ($a) => self::coachKey($a), label: 'grant:roundApplicants.listUi.filterCoach', fallback: 'Business Coach review', values: ['none', 'in_progress', 'done'])
                ->options('reviewer', fn ($a) => collect($a['reviewers'] ?? [])->pluck('user_id')->filter()->map(fn ($id) => (string) $id)->all(),
                    label: 'app:reviewer', fallback: 'Business Coach', searchable: true,
                    optionLabels: fn (array $ids) => $rows->flatMap(fn ($a) => $a['reviewers'] ?? [])->filter(fn ($r) => in_array((string) $r['user_id'], $ids, true))
                        ->mapWithKeys(fn ($r) => [(string) $r['user_id'] => $r['name'] ?: '#'.$r['user_id']])->all())
            : $list->filter('coach', fn ($a) => self::coachKey($a));
        $ai
            ? $list->options('ai', fn ($a) => self::aiKey($a['ai_review']['status'] ?? null), label: 'app:aiStatus', values: ['waiting', 'working', 'ready', 'attention', 'none'])
            : $list->filter('ai', fn ($a) => self::aiKey($a['ai_review']['status'] ?? null));

        $list->options('band', fn ($a) => self::band(self::score($a)), label: 'grant:roundApplicants.listUi.filterBand', fallback: 'Score band', values: ['high', 'mid', 'low'])
            ->numberRange('score', fn ($a) => self::score($a), label: 'app:score')
            ->options('knockout', fn ($a) => self::knockoutKey($a), label: 'grant:roundApplicants.listUi.filterKnockout', fallback: 'Knockout questions')
            ->options('sector', fn ($a) => self::clean($a['sector'] ?? null), label: 'app:sector')
            ->options('location', fn ($a) => self::place($a['headquarters_location'] ?? null), label: 'app:location')
            ->numberRange('requested', fn ($a) => is_numeric($a['total_amount_requested'] ?? null) ? (float) $a['total_amount_requested'] : null, label: 'app:listFilterAmountRequested', fallback: 'Amount requested');
        if ($rows->contains(fn ($a) => ($a['match_score'] ?? null) !== null)) {
            $list->numberRange('match', fn ($a) => is_numeric($a['match_score'] ?? null) ? (float) $a['match_score'] : null, label: 'app:matchScore')
                ->options('match_tier', fn ($a) => \App\Support\MatchTier::of($a['match_score'] ?? null), label: 'app:matchTierLabel', fallback: 'Match tier', values: \App\Support\MatchTier::KEYS);
        }
        if (! $round || $round->advancement_mode !== 'manual') {
            $list->boolean('eligible', fn ($a) => self::eligible($a), label: 'app:eligible');
        } else {
            $list->filter('eligible', fn ($a) => self::eligible($a) ? 'yes' : 'no');
        }

        return $list
            ->dateRange('submitted', fn ($a) => self::submittedAt($a), label: 'app:submitted')
            ->sortable([
                'name' => fn ($a) => mb_strtolower(self::name($a)),
                'score' => fn ($a) => self::score($a) ?? -1,
                'submitted' => fn ($a) => self::submittedAt($a),
            ]);
    }

    /** "Westlands, Nairobi, Kenya" → "Nairobi, Kenya" (the place people filter by), '' when not given. */
    public static function place($location): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', self::clean($location))), fn ($p) => $p !== ''));

        return implode(', ', array_slice($parts, -2));
    }

    public const FACETS = ['status', 'review', 'coach', 'ai', 'band', 'knockout', 'sector', 'match_tier'];
}
