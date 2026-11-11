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

        return response()->json(['data' => AiReviewResource::make($review->load('findings'))]);
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
