<?php

namespace App\Http\Controllers\Program\Ai;

use App\Http\Controllers\Controller;
use App\Models\Programs\Ai\ApplicationAiFinding;
use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Service\AiReview\AiAudit;
use App\Service\AiReview\AiReviewDispatcher;
use App\Service\AiReview\AiReviewResource;
use App\Service\AiReview\PrivacyGate;
use App\Service\AiReview\RateGovernor;
use App\Service\Program\ProgramAccess;
use Illuminate\Http\Request;

class AiReviewController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    /** POST ai-privacy-ack: the organisation accepts that applicant content may be processed by the active AI provider. */
    public function privacyAck(Request $request, PrivacyGate $gate)
    {
        $user = auth()->user();
        $request->validate(['confirm' => 'required|accepted']);

        $org = $user->organization_id ? \App\Models\Organizations\Organization::find($user->organization_id) : null;
        $user->loadMissing('organizationRole.role');
        $role = $user->organizationRole?->role?->name;
        if (! $org || ((int) $org->owner_user_id !== (int) $user->id && ! in_array($role, ['super_admin', 'super-admin', 'admin', 'editor'], true))) {
            return response()->json(['message' => 'Only an organization owner, admin or editor can do this.'], 403);
        }

        $provider = (string) config('ai_review.default_provider');
        if (! $gate->providerConfig($provider)) {
            return response()->json(['message' => 'The AI provider is not configured.'], 422);
        }

        $org->update(['ai_data_ack_at' => now(), 'ai_data_ack_by' => $user->id, 'ai_data_ack_provider' => $provider]);
        AiAudit::record('privacy_ack', null, null, ['provider' => $provider, 'organization_id' => $org->id]);

        return response()->json(['message' => 'Acknowledgment recorded.', 'data' => ['provider' => $provider, 'acknowledged_at' => $org->ai_data_ack_at]]);
    }

    /** GET rounds/{round}/ai-reviews: owner dashboard for the round. */
    public function roundOverview(ProgramRound $round, RateGovernor $governor, PrivacyGate $gate)
    {
        if (! ProgramAccess::owns($round->program)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $reviews = ApplicationAiReview::where('round_id', $round->id)->where('status', '!=', 'stale')->get()->keyBy('application_id');
        $apps = ProgramApplication::where('current_round_id', $round->id)->get(['id', 'startup_name', 'contact_person_name', 'round_status']);

        $provider = (string) config('ai_review.default_provider');
        $capacity = $governor->capacity($provider);
        $pending = $apps->filter(fn ($a) => ! in_array($reviews[$a->id]->status ?? null, ['ready'], true))->count();
        $eta = $capacity['requests_remaining_today'] > 0 ? now()->addMinutes((int) ceil($pending / max(1, $capacity['rpm'])))->toIso8601String() : null;

        return response()->json(['data' => [
            'review_method' => $round->review_method,
            'counts' => $reviews->groupBy('status')->map->count(),
            'applications' => $apps->map(fn ($a) => [
                'application_id' => $a->id,
                'applicant_name' => $a->startup_name ?: $a->contact_person_name,
                'status' => $reviews[$a->id]->status ?? 'not_started',
                'suggested_total' => $reviews[$a->id]->suggested_total ?? null,
                'verified_quote_ratio' => $reviews[$a->id]->quality['verified_quote_ratio'] ?? null,
                'error' => $reviews[$a->id]->error ?? null,
                'completed_at' => $reviews[$a->id]->completed_at ?? null,
            ])->values(),
            'provider' => $provider,
            'model' => config("ai_review.providers.{$provider}.model"),
            'privacy_gate_satisfied' => $gate->satisfied($provider, $gate->organizationFor($round->program)),
            'capacity' => $capacity,
            'estimated_completion' => $eta,
            'review_notified_at' => $round->ai_review_notified_at,
            'capacity_notified_at' => $round->ai_capacity_notified_at,
        ]]);
    }

    /**
     * GET rounds/{round}/ai-reviews/activity: what the AI is doing for the round right now, in a form that stays small however many
     * applications there are (counts by SQL, and only the few newest rows of each kind).
     */
    public function roundActivity(ProgramRound $round, RateGovernor $governor, PrivacyGate $gate)
    {
        if (! ProgramAccess::owns($round->program)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // A rerun may leave an older completed row for the same application. Activity is current state, not an
        // attempt log, so every count and list uses only that application's newest non-stale review.
        $latestReviewIds = ApplicationAiReview::query()
            ->where('round_id', $round->id)->where('status', '!=', 'stale')
            // Only applications that are in this round and submitted count, so every number adds up to the same total
            ->whereIn('application_id', ProgramApplication::where('current_round_id', $round->id)->whereIn('round_status', ['submitted', 'scored'])->notAutomated()->select('id'))
            ->selectRaw('MAX(id)')->groupBy('application_id');
        $base = fn () => ApplicationAiReview::query()
            ->join('program_applications as pa', 'pa.id', '=', 'application_ai_reviews.application_id')
            ->whereIn('application_ai_reviews.id', clone $latestReviewIds)
            ->select('application_ai_reviews.*', 'pa.startup_name', 'pa.contact_person_name');
        $name = fn ($r) => $r->startup_name ?: ($r->contact_person_name ?: '#'.$r->application_id);

        $byStatus = ApplicationAiReview::whereIn('id', clone $latestReviewIds)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $submitted = ProgramApplication::where('current_round_id', $round->id)->whereIn('round_status', ['submitted', 'scored'])->notAutomated()->count();
        $working = (int) ($byStatus['extracting'] ?? 0) + (int) ($byStatus['analyzing'] ?? 0);
        $queued = (int) ($byStatus['queued'] ?? 0);
        $ready = (int) ($byStatus['ready'] ?? 0);
        $attention = (int) ($byStatus['failed'] ?? 0) + (int) ($byStatus['needs_attention'] ?? 0);
        // Submitted applications that have no review row yet are also waiting
        $notQueuedYet = max(0, $submitted - $ready - $attention - $working - $queued);
        $waiting = $queued + $notQueuedYet;

        // The application being worked on now (the longest-running one if several run at once)
        $now = $base()->whereIn('application_ai_reviews.status', ['extracting', 'analyzing'])->orderBy('application_ai_reviews.started_at')->first();

        // Waiting requests, the latest at the top: reviews already queued, and submitted applications that have no review row yet
        $unreviewed = fn () => ProgramApplication::where('current_round_id', $round->id)->whereIn('round_status', ['submitted', 'scored'])->notAutomated()
            ->whereNotIn('id', ApplicationAiReview::where('round_id', $round->id)->where('status', '!=', 'stale')->select('application_id'));
        $queuedRows = $base()->where('application_ai_reviews.status', 'queued')->orderByDesc('application_ai_reviews.updated_at')->limit(15)->get()
            ->map(fn ($r) => ['application_id' => $r->application_id, 'applicant_name' => $name($r), 'queued_at' => $r->updated_at]);
        $freshRows = $unreviewed()->orderByDesc('id')->limit(15)->get(['id', 'startup_name', 'contact_person_name', 'created_at', 'updated_at'])
            ->map(fn ($a) => ['application_id' => $a->id, 'applicant_name' => $a->startup_name ?: ($a->contact_person_name ?: '#'.$a->id), 'queued_at' => $a->updated_at ?? $a->created_at]);
        $queue = $queuedRows->concat($freshRows)->sortByDesc(fn ($q) => $q['queued_at']->timestamp)->take(15)->values();

        // The one the queue takes next (oldest first, the order it really works in), and whether anything is moving at all
        $nextRow = $base()->where('application_ai_reviews.status', 'queued')->orderBy('application_ai_reviews.updated_at')->orderBy('application_ai_reviews.id')->first();
        $nextFresh = $unreviewed()->orderBy('id')->first(['id', 'startup_name', 'contact_person_name', 'created_at', 'updated_at']);
        $next = $nextRow
            ? (object) ['application_id' => $nextRow->application_id, 'name' => $name($nextRow), 'at' => $nextRow->updated_at]
            : ($nextFresh ? (object) ['application_id' => $nextFresh->id, 'name' => $nextFresh->startup_name ?: ($nextFresh->contact_person_name ?: '#'.$nextFresh->id), 'at' => $nextFresh->updated_at ?? $nextFresh->created_at] : null);
        // Stopped by the program owner: nothing is meant to be running, so that is not a fault
        $stopped = $round->ai_review_paused_at !== null;

        // Finished, newest first, with what the timeline needs
        $recent = $base()->whereIn('application_ai_reviews.status', ['ready', 'failed', 'needs_attention'])->orderByDesc('application_ai_reviews.completed_at')->limit(8)->get();
        // The tabs: finished well, and needing a person, each its own newest-first list
        $doneList = $base()->where('application_ai_reviews.status', 'ready')->orderByDesc('application_ai_reviews.completed_at')->limit(15)->get();
        $attentionList = $base()->whereIn('application_ai_reviews.status', ['failed', 'needs_attention'])->orderByDesc('application_ai_reviews.completed_at')->limit(15)->get();
        $row = fn ($r) => [
            'application_id' => $r->application_id, 'applicant_name' => $name($r), 'status' => $r->status,
            'queued_at' => $r->created_at?->toIso8601String(), 'started_at' => $r->started_at?->toIso8601String(), 'completed_at' => $r->completed_at?->toIso8601String(),
            'seconds' => $r->started_at && $r->completed_at ? max(1, $r->started_at->diffInSeconds($r->completed_at)) : null,
            'suggested_total' => $r->suggested_total, 'error' => $r->error,
        ];

        // How long one application takes, from the most recent ones that really ran
        $durations = ApplicationAiReview::where('round_id', $round->id)->where('status', 'ready')->whereNotNull('started_at')->whereNotNull('completed_at')
            ->orderByDesc('completed_at')->limit(30)->get(['started_at', 'completed_at'])
            ->map(fn ($r) => max(1, $r->started_at->diffInSeconds($r->completed_at)))->all();
        $provider = (string) config('ai_review.default_provider');
        $capacity = $governor->capacity($provider);
        // Never faster than the provider's own pace: one request a minute costs at least 60 / rpm seconds
        $floor = $capacity['rpm'] > 0 ? (int) ceil(60 / $capacity['rpm']) : 0;
        // The middle value, so one review that waited hours for the provider's quota does not stretch every estimate
        sort($durations);
        $median = $durations ? $durations[(int) floor((count($durations) - 1) / 2)] : null;
        $perApplication = $median !== null ? max($floor, (int) $median) : null;
        $cooldown = $governor->cooldownRemaining($provider);
        // Nothing is working although applications have waited over two minutes, and there is no reason for it: not stopped by the owner,
        // not the provider's pause or daily limit (that is "paused"), and no background worker running for this round
        $workerRunning = \Illuminate\Support\Facades\Cache::has("ai:work:{$round->id}");
        $stalled = ! $stopped && $working === 0 && $next !== null && $next->at->lt(now()->subMinutes(2))
            && ! $workerRunning && $cooldown === 0 && $capacity['requests_remaining_today'] > 0;
        $remaining = $waiting + $working;
        // Historical speed cannot predict a finish while the queue worker is offline.
        $finishAt = ! $stalled && $perApplication && $remaining > 0 ? now()->addSeconds($cooldown + $remaining * $perApplication)->toIso8601String() : null;

        $state = $stopped ? 'stopped' : ($working > 0 ? 'working' : ($stalled ? 'stalled' : ($waiting > 0 ? ($cooldown > 0 || $capacity['requests_remaining_today'] <= 0 ? 'paused' : 'starting') : ($submitted > 0 && $ready + $attention >= $submitted ? 'done' : 'idle'))));

        return response()->json(['data' => [
            'state' => $state,
            'counts' => ['total' => $submitted, 'done' => $ready, 'working' => $working, 'waiting' => $waiting, 'attention' => $attention],
            'now' => $now ? ['application_id' => $now->application_id, 'applicant_name' => $name($now), 'step' => $now->status, 'started_at' => $now->started_at?->toIso8601String()] : null,
            'next' => $next ? ['application_id' => $next->application_id, 'applicant_name' => $next->name, 'queued_at' => $next->at->toIso8601String()] : null,
            'stalled' => $stalled,
            'stopped' => $stopped,
            'queue' => $queue->map(fn ($q) => ['application_id' => $q['application_id'], 'applicant_name' => $q['applicant_name'], 'queued_at' => $q['queued_at']->toIso8601String()])->values(),
            'queue_more' => max(0, $waiting - $queue->count()),
            'recent' => $recent->map($row)->values(),
            'done_list' => $doneList->map($row)->values(),
            'attention_list' => $attentionList->map($row)->values(),
            'seconds_per_application' => $perApplication,
            'estimated_finish_at' => $finishAt,
            'paused_for_seconds' => $cooldown ?: null,
            'daily_limit_reached' => $capacity['requests_remaining_today'] <= 0,
            'provider' => $provider,
            'model' => config("ai_review.providers.{$provider}.model"),
            'privacy_gate_satisfied' => $gate->satisfied($provider, $gate->organizationFor($round->program)),
            'server_time' => now()->toIso8601String(),
        ]]);
    }

    /**
     * GET rounds/{round}/ai-reviews/items?tab=pending|success|attention&page=1&per_page=10: one page of the AI tab's table, newest first.
     * pending = waiting or being worked on (including applications with no review yet), success = scored, attention = the AI could not finish.
     */
    public function roundItems(Request $request, ProgramRound $round)
    {
        if (! ProgramAccess::owns($round->program)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        $data = $request->validate([
            'tab' => 'required|in:pending,success,attention',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:10,25,50,100',
        ]);

        // Each application's newest non-stale review (or none)
        $latest = ApplicationAiReview::query()->where('round_id', $round->id)->where('status', '!=', 'stale')
            ->selectRaw('application_id, MAX(id) as review_id')->groupBy('application_id');
        $query = \Illuminate\Support\Facades\DB::table('program_applications as pa')
            ->leftJoinSub($latest, 'lr', 'lr.application_id', '=', 'pa.id')
            ->leftJoin('application_ai_reviews as r', 'r.id', '=', 'lr.review_id')
            ->where('pa.current_round_id', $round->id)->whereIn('pa.round_status', ['submitted', 'scored']);
        if (! \App\Service\Misc\AutomatedAccounts::$reviewWithAi) {
            $query->whereNotIn('pa.user_id', \Illuminate\Support\Facades\DB::table('users')->where('email', 'like', \App\Service\Misc\AutomatedAccounts::EMAIL_LIKE)->select('id'));
        }

        if ($data['tab'] === 'pending') {
            $query->where(fn ($q) => $q->whereNull('r.id')->orWhereIn('r.status', ['queued', 'extracting', 'analyzing']))
                ->orderByRaw('COALESCE(r.updated_at, pa.updated_at) DESC');
        } elseif ($data['tab'] === 'success') {
            $query->where('r.status', 'ready')->orderByDesc('r.completed_at');
        } else {
            $query->whereIn('r.status', ['failed', 'needs_attention'])->orderByDesc('r.completed_at');
        }

        $page = $query->orderByDesc('pa.id')
            ->select('pa.id as application_id', 'pa.startup_name', 'pa.contact_person_name', 'pa.updated_at as app_updated_at', 'r.status', 'r.updated_at as review_updated_at', 'r.started_at', 'r.completed_at', 'r.suggested_total', 'r.error')
            ->paginate((int) ($data['per_page'] ?? 10), ['*'], 'page', (int) ($data['page'] ?? 1));

        $iso = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->toIso8601String() : null;

        return response()->json(['data' => [
            'items' => collect($page->items())->map(fn ($r) => [
                'application_id' => $r->application_id,
                'applicant_name' => $r->startup_name ?: ($r->contact_person_name ?: '#'.$r->application_id),
                'status' => $r->status ?? 'not_started',
                'at' => $iso($data['tab'] === 'pending' ? ($r->review_updated_at ?? $r->app_updated_at) : $r->completed_at),
                'seconds' => $r->started_at && $r->completed_at ? max(1, \Illuminate\Support\Carbon::parse($r->started_at)->diffInSeconds(\Illuminate\Support\Carbon::parse($r->completed_at))) : null,
                'suggested_total' => $r->suggested_total !== null ? (float) $r->suggested_total : null,
                'error' => $r->error,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]]);
    }

    /**
     * POST rounds/{round}/ai-reviews/rerun-attention: try again the reviews the AI could not finish (all of them, or only the application_ids
     * given). A review that finished well is never run again: it would spend tokens, and the reviewer edits the scores by hand.
     */
    public function rerunAttention(Request $request, ProgramRound $round)
    {
        if (! ProgramAccess::owns($round->program)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        if (! $round->usesAi()) {
            return response()->json(['message' => 'This round does not use AI review.'], 422);
        }
        if ($round->status === 'finalized') {
            return response()->json(['message' => 'This round has been finalized.'], 422);
        }
        if (! config('ai_review.enabled')) {
            return response()->json(['message' => 'AI review is not switched on.'], 422);
        }
        $data = $request->validate(['application_ids' => 'nullable|array|max:500', 'application_ids.*' => 'integer']);

        $latestIds = ApplicationAiReview::query()->where('round_id', $round->id)->where('status', '!=', 'stale')
            ->whereIn('application_id', ProgramApplication::where('current_round_id', $round->id)->whereIn('round_status', ['submitted', 'scored'])->notAutomated()->select('id'))
            ->selectRaw('MAX(id)')->groupBy('application_id');
        $query = ApplicationAiReview::whereIn('id', $latestIds)->whereIn('status', ['failed', 'needs_attention']);
        $total = (clone $query)->count();
        if (! empty($data['application_ids'])) {
            $query->whereIn('application_id', $data['application_ids']);
        }
        $reviews = $query->orderBy('id')->limit(500)->get();

        // Running them again lifts a stop, like Continue
        if ($reviews->isNotEmpty() && $round->ai_review_paused_at) {
            $round->forceFill(['ai_review_paused_at' => null])->save();
        }
        foreach ($reviews as $review) {
            $review->update(['status' => 'queued', 'attempts' => 0, 'error' => null, 'started_at' => null, 'completed_at' => null]);
        }
        if ($reviews->isNotEmpty()) {
            if (\App\Service\AiReview\BackgroundWork::needed()) {
                \App\Service\AiReview\BackgroundWork::start($round);
            } else {
                $reviews->each(fn ($review) => \App\Jobs\RunApplicationAiReviewJob::dispatch($review->id));
            }
        }
        AiAudit::record('rerun_attention', $round->id, null, ['count' => $reviews->count()]);

        return response()->json(['message' => 'AI review started again.', 'data' => ['queued' => $reviews->count(), 'remaining' => max(0, $total - $reviews->count())]], 202);
    }

    /**
     * POST rounds/{round}/ai-reviews/stop: stop sending this round's applications to the AI. A review already being written finishes;
     * nothing new starts, and nothing is lost: Continue starts the waiting ones again.
     */
    public function stop(ProgramRound $round)
    {
        if (! ProgramAccess::owns($round->program)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        if (! $round->usesAi()) {
            return response()->json(['message' => 'This round does not use AI review.'], 422);
        }

        $round->forceFill(['ai_review_paused_at' => now()])->save();
        AiAudit::record('stop', $round->id);

        return response()->json(['message' => 'AI review stopped.', 'data' => ['stopped_at' => $round->ai_review_paused_at->toIso8601String()]]);
    }

    /**
     * POST rounds/{round}/ai-reviews/resume: carry on with the applications that are still waiting (never re-scores a finished one).
     * Picks up queued reviews and submitted applications that have none yet, a limited number at a time; the queue then works through
     * them one by one at the provider's pace.
     */
    public function resume(ProgramRound $round)
    {
        if (! ProgramAccess::owns($round->program)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        if (! $round->usesAi()) {
            return response()->json(['message' => 'This round does not use AI review.'], 422);
        }
        if ($round->status === 'finalized') {
            return response()->json(['message' => 'This round has been finalized.'], 422);
        }
        if (! config('ai_review.enabled')) {
            return response()->json(['message' => 'AI review is not switched on.'], 422);
        }

        // Continuing lifts a stop
        if ($round->ai_review_paused_at) {
            $round->forceFill(['ai_review_paused_at' => null])->save();
        }

        // With a queue worker, the waiting reviews are queued and the worker takes them one by one. Without one (QUEUE_CONNECTION=sync) nothing
        // would ever pick them up, so a background command is started that works through the whole list by itself, one application after another.
        // Either way the page only has to ask once.
        $sync = config('queue.default') === 'sync';
        $limit = $sync ? 0 : 200;
        $started = 0;
        if ($sync) {
            $started = ApplicationAiReview::where('round_id', $round->id)->where('status', 'queued')->count()
                + ProgramApplication::where('current_round_id', $round->id)->whereIn('round_status', ['submitted', 'scored'])->notAutomated()
                    ->whereNotIn('id', ApplicationAiReview::where('round_id', $round->id)->where('status', '!=', 'stale')->select('application_id'))->count();
            if ($started > 0 && ! \App\Service\AiReview\BackgroundWork::start($round)) {
                return response()->json(['message' => 'The AI could not be started in the background. Start the queue worker on the server (php artisan queue:work --queue=ai-review).'], 500);
            }
        } else {
            // 1. Reviews already waiting (the job is unique per review, so asking again never doubles the work)
            $stuckBefore = now()->subMinutes(2);
            ApplicationAiReview::where('round_id', $round->id)->where('status', 'queued')->orderBy('id')->limit($limit)->get(['id', 'updated_at'])
                ->each(function ($review) use (&$started, $stuckBefore) {
                    // A review untouched for a couple of minutes may hold a leftover "one at a time" lock from a run that never finished
                    if ($review->updated_at->lt($stuckBefore)) {
                        \Illuminate\Support\Facades\Cache::lock('laravel_unique_job:'.\App\Jobs\RunApplicationAiReviewJob::class.'ai-review-'.$review->id)->forceRelease();
                    }
                    \App\Jobs\RunApplicationAiReviewJob::dispatch($review->id);
                    $started++;
                });
            // 2. Submitted applications that have no review at all yet
            if ($started < $limit) {
                $dispatcher = app(AiReviewDispatcher::class);
                ProgramApplication::where('current_round_id', $round->id)->whereIn('round_status', ['submitted', 'scored'])->notAutomated()
                    ->whereNotIn('id', ApplicationAiReview::where('round_id', $round->id)->where('status', '!=', 'stale')->select('application_id'))
                    ->orderBy('id')->limit($limit - $started)->get()
                    ->each(function ($application) use ($dispatcher, $round, &$started) {
                        if ($dispatcher->dispatchFor($application, $round)) {
                            $started++;
                        }
                    });
            }
        }
        AiAudit::record('resume', $round->id, null, ['count' => $started]);

        // With no background worker each review ran inside this request, so the page asks again until nothing is left
        return response()->json(['message' => 'AI review continues.', 'data' => ['started' => $started, 'background' => $sync]], 202);
    }

    /** POST applications/{application}/ai-review/rerun */
    public function rerun(ProgramApplication $application)
    {
        $round = $application->currentRound;
        if (! $round || ! ProgramAccess::owns($round->program)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        if (! $round->usesAi()) {
            return response()->json(['message' => 'This round does not use AI review.'], 422);
        }
        if ($round->status === 'finalized') {
            return response()->json(['message' => 'This round has been finalized.'], 422);
        }
        if (! config('ai_review.enabled')) {
            return response()->json(['message' => 'AI review is not switched on.'], 422);
        }

        // The AI scores an application once. A finished review is never run again (it costs tokens): the reviewer edits the scores by hand.
        // Only an application with no review yet, or one the AI could not finish, can be tried again.
        $current = ApplicationAiReview::where(['application_id' => $application->id, 'round_id' => $round->id])->where('status', '!=', 'stale')->latest('id')->first();
        if ($current && ! in_array($current->status, ['failed', 'needs_attention', 'queued'], true)) {
            return response()->json(['message' => $current->status === 'ready'
                ? 'The AI has already scored this application. If a score looks wrong, change it yourself.'
                : 'The AI is already working on this application.'], 422);
        }

        $review = app(AiReviewDispatcher::class)->dispatchFor($application, $round, true);
        AiAudit::record('rerun', $round->id, $application->id);

        return response()->json(['message' => 'AI review queued.', 'data' => ['review_id' => $review?->id, 'status' => $review?->status]], 202);
    }

    /** GET applications/{application}/ai-review */
    public function show(ProgramApplication $application)
    {
        [$round, $denied] = $this->authorizeApplication($application);
        if ($denied) {
            return $denied;
        }

        $review = ApplicationAiReview::where(['application_id' => $application->id, 'round_id' => $round->id])
            ->where('status', '!=', 'stale')->latest('id')->first()
            ?? ApplicationAiReview::where(['application_id' => $application->id, 'round_id' => $round->id])->latest('id')->first();

        if (! $review) {
            return response()->json(['data' => null, 'message' => 'No AI review has run for this application yet.']);
        }

        return response()->json(['data' => AiReviewResource::make($review->load('findings'), $round)]);
    }

    /** POST applications/{application}/ai-review/findings: a human finding, labelled as human. */
    public function storeFinding(Request $request, ProgramApplication $application)
    {
        [$round, $denied] = $this->authorizeApplication($application);
        if ($denied) {
            return $denied;
        }

        $data = $request->validate([
            'dimension' => 'required|in:'.implode(',', [...ApplicationAiFinding::DIMENSIONS, 'data_quality']),
            'polarity' => 'required|in:'.implode(',', ApplicationAiFinding::POLARITIES),
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
        ]);

        $review = ApplicationAiReview::where(['application_id' => $application->id, 'round_id' => $round->id])->where('status', '!=', 'stale')->latest('id')->first();
        if (! $review) {
            return response()->json(['message' => 'There is no AI review to add a finding to yet.'], 422);
        }

        $finding = ApplicationAiFinding::create($data + [
            'review_id' => $review->id, 'author_type' => 'human', 'author_user_id' => auth()->id(), 'status' => 'open',
        ]);

        return response()->json(['message' => 'Finding added.', 'data' => AiReviewResource::finding($finding)], 201);
    }

    /** PATCH ai-findings/{finding}: confirm | dismiss | override (the original AI text is preserved). */
    public function updateFinding(Request $request, ApplicationAiFinding $finding)
    {
        $review = $finding->review;
        $application = $review->application;
        [$round, $denied] = $this->authorizeApplication($application);
        if ($denied) {
            return $denied;
        }
        if ($round->status === 'finalized') {
            return response()->json(['message' => 'This round has been finalized.'], 422);
        }

        $data = $request->validate([
            'action' => 'required|in:confirm,dismiss,override',
            'body' => 'required_if:action,override|nullable|string|max:5000',
            'reviewer_note' => 'required_if:action,override|nullable|string|max:2000',
        ]);

        if ($finding->author_type === 'human' && $data['action'] === 'override' && (int) $finding->author_user_id !== (int) auth()->id()) {
            return response()->json(['message' => 'You can only change your own findings.'], 403);
        }

        $update = ['reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'reviewer_note' => $data['reviewer_note'] ?? null];
        match ($data['action']) {
            'confirm' => $update['status'] = 'confirmed',
            'dismiss' => $update['status'] = 'dismissed',
            'override' => $update += ['status' => 'overridden', 'original_body' => $finding->original_body ?? $finding->body, 'body' => $data['body']],
        };
        $finding->update($update);
        AiAudit::record('finding_reviewed', $round->id, $application->id, ['finding_id' => $finding->id, 'action' => $data['action']]);

        return response()->json(['message' => 'Finding updated.', 'data' => AiReviewResource::finding($finding->fresh())]);
    }

    /**
     * The program owner (and their team), or a reviewer assigned to this application. Applicants never.
     *
     * @return array{0:?ProgramRound, 1:?\Illuminate\Http\JsonResponse}
     */
    private function authorizeApplication(ProgramApplication $application): array
    {
        $round = $application->currentRound;
        if (! $round || ! $round->usesAi()) {
            return [null, response()->json(['message' => 'This round does not use AI review.'], 404)];
        }
        $user = auth()->user();
        if (ProgramAccess::owns($round->program)) {
            return [$round, null];
        }

        $assigned = ReviewerApplicationAssignment::where(['round_id' => $round->id, 'application_id' => $application->id, 'reviewer_id' => $user->id])->exists();
        if ($assigned) {
            return [$round, null];
        }

        return [null, response()->json(['message' => 'You do not have access to this application.'], 403)];
    }
}
