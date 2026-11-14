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
        if (! ProgramAccess::owns($round->program)) return response()->json(['message' => 'Unauthorized.'], 403);

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
        if (! ProgramAccess::owns($round->program)) return response()->json(['message' => 'Unauthorized.'], 403);

        $base = fn () => ApplicationAiReview::query()
            ->join('program_applications as pa', 'pa.id', '=', 'application_ai_reviews.application_id')
            ->where('application_ai_reviews.round_id', $round->id)->where('application_ai_reviews.status', '!=', 'stale')
            ->select('application_ai_reviews.*', 'pa.startup_name', 'pa.contact_person_name');
        $name = fn ($r) => $r->startup_name ?: ($r->contact_person_name ?: '#'.$r->application_id);

        $byStatus = ApplicationAiReview::where('round_id', $round->id)->where('status', '!=', 'stale')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $submitted = ProgramApplication::where('current_round_id', $round->id)->whereIn('round_status', ['submitted', 'scored'])->count();
        $working = (int) ($byStatus['extracting'] ?? 0) + (int) ($byStatus['analyzing'] ?? 0);
        $queued = (int) ($byStatus['queued'] ?? 0);
        $ready = (int) ($byStatus['ready'] ?? 0);
        $attention = (int) ($byStatus['failed'] ?? 0) + (int) ($byStatus['needs_attention'] ?? 0);
        // Submitted applications that have no review row yet are also waiting
        $notQueuedYet = max(0, $submitted - $ready - $attention - $working - $queued);
        $waiting = $queued + $notQueuedYet;

        // The application being worked on now (the longest-running one if several run at once)
        $now = $base()->whereIn('application_ai_reviews.status', ['extracting', 'analyzing'])->orderBy('application_ai_reviews.started_at')->first();

        // Waiting requests, the latest at the top
        $queue = $base()->where('application_ai_reviews.status', 'queued')->orderByDesc('application_ai_reviews.updated_at')->limit(15)->get();

        // Finished, newest first, with what the timeline needs
        $recent = $base()->whereIn('application_ai_reviews.status', ['ready', 'failed', 'needs_attention'])->orderByDesc('application_ai_reviews.completed_at')->limit(8)->get();

        // How long one application takes, from the most recent ones that really ran
        $durations = ApplicationAiReview::where('round_id', $round->id)->where('status', 'ready')->whereNotNull('started_at')->whereNotNull('completed_at')
            ->orderByDesc('completed_at')->limit(30)->get(['started_at', 'completed_at'])
            ->map(fn ($r) => max(1, $r->started_at->diffInSeconds($r->completed_at)))->all();
        $provider = (string) config('ai_review.default_provider');
        $capacity = $governor->capacity($provider);
        // Never faster than the provider's own pace: one request a minute costs at least 60 / rpm seconds
        $floor = $capacity['rpm'] > 0 ? (int) ceil(60 / $capacity['rpm']) : 0;
        $perApplication = $durations ? max($floor, (int) round(array_sum($durations) / count($durations))) : null;
        $cooldown = $governor->cooldownRemaining($provider);
        $remaining = $waiting + $working;
        $finishAt = $perApplication && $remaining > 0 ? now()->addSeconds($cooldown + $remaining * $perApplication)->toIso8601String() : null;

        $state = $working > 0 ? 'working' : ($waiting > 0 ? ($cooldown > 0 || $capacity['requests_remaining_today'] <= 0 ? 'paused' : 'starting') : ($submitted > 0 && $ready + $attention >= $submitted ? 'done' : 'idle'));

        return response()->json(['data' => [
            'state' => $state,
            'counts' => ['total' => $submitted, 'done' => $ready, 'working' => $working, 'waiting' => $waiting, 'attention' => $attention],
            'now' => $now ? ['application_id' => $now->application_id, 'applicant_name' => $name($now), 'step' => $now->status, 'started_at' => $now->started_at?->toIso8601String()] : null,
            'queue' => $queue->map(fn ($r) => ['application_id' => $r->application_id, 'applicant_name' => $name($r), 'queued_at' => $r->updated_at->toIso8601String()])->values(),
            'queue_more' => max(0, $waiting - $queue->count()),
            'recent' => $recent->map(fn ($r) => [
                'application_id' => $r->application_id, 'applicant_name' => $name($r), 'status' => $r->status,
                'queued_at' => $r->created_at?->toIso8601String(), 'started_at' => $r->started_at?->toIso8601String(), 'completed_at' => $r->completed_at?->toIso8601String(),
                'seconds' => $r->started_at && $r->completed_at ? max(1, $r->started_at->diffInSeconds($r->completed_at)) : null,
                'suggested_total' => $r->suggested_total, 'error' => $r->error,
            ])->values(),
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

    /** POST applications/{application}/ai-review/rerun */
    public function rerun(ProgramApplication $application)
    {
        $round = $application->currentRound;
        if (! $round || ! ProgramAccess::owns($round->program)) return response()->json(['message' => 'Unauthorized.'], 403);
        if (! $round->usesAi()) return response()->json(['message' => 'This round does not use AI review.'], 422);
        if ($round->status === 'finalized') return response()->json(['message' => 'This round has been finalized.'], 422);
        if (! config('ai_review.enabled')) return response()->json(['message' => 'AI review is not switched on.'], 422);

        $review = app(AiReviewDispatcher::class)->dispatchFor($application, $round, true);
        AiAudit::record('rerun', $round->id, $application->id);

        return response()->json(['message' => 'AI review queued.', 'data' => ['review_id' => $review?->id, 'status' => $review?->status]], 202);
    }

    /** GET applications/{application}/ai-review */
    public function show(ProgramApplication $application)
    {
        [$round, $denied] = $this->authorizeApplication($application);
        if ($denied) return $denied;

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
        if ($denied) return $denied;

        $data = $request->validate([
            'dimension' => 'required|in:'.implode(',', [...ApplicationAiFinding::DIMENSIONS, 'data_quality']),
            'polarity' => 'required|in:'.implode(',', ApplicationAiFinding::POLARITIES),
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
        ]);

        $review = ApplicationAiReview::where(['application_id' => $application->id, 'round_id' => $round->id])->where('status', '!=', 'stale')->latest('id')->first();
        if (! $review) return response()->json(['message' => 'There is no AI review to add a finding to yet.'], 422);

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
        if ($denied) return $denied;
        if ($round->status === 'finalized') return response()->json(['message' => 'This round has been finalized.'], 422);

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
     * @return array{0:?ProgramRound, 1:?\Illuminate\Http\JsonResponse}
     */
    private function authorizeApplication(ProgramApplication $application): array
    {
        $round = $application->currentRound;
        if (! $round || ! $round->usesAi()) {
            return [null, response()->json(['message' => 'This round does not use AI review.'], 404)];
        }
        $user = auth()->user();
        if (ProgramAccess::owns($round->program)) return [$round, null];

        $assigned = ReviewerApplicationAssignment::where(['round_id' => $round->id, 'application_id' => $application->id, 'reviewer_id' => $user->id])->exists();
        if ($assigned) return [$round, null];

        return [null, response()->json(['message' => 'You do not have access to this application.'], 403)];
    }
}
