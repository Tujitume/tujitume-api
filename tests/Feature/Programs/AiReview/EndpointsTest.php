<?php

use App\Models\Programs\Ai\ApplicationAiFinding;
use App\Service\AiReview\AiReviewDispatcher;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Programs\AiReview\AiReviewTestCase;

uses(AiReviewTestCase::class);

beforeEach(function () {
    $this->aiRound('ai_assisted');
    $this->assignReviewer();
    $this->llm->default = $this->report();
    app(AiReviewDispatcher::class)->dispatchFor($this->application);
    $this->base = "/api/v1/programs/applications/{$this->application->id}/ai-review";
});

it('shows the owner the report with explicit AI provenance and source links', function () {
    $res = $this->actingAsOrgOwner()->getJson($this->base)->assertOk();
    $d = $res->json('data');
    expect($d['origin'])->toBe('ai')->and($d['status'])->toBe('ready')->and($d['suggested_scores'][0]['origin'])->toBe('ai')->and($d['quality']['verified_quote_ratio'])->toEqual(1);
    $f = $d['findings'][0];
    expect($f['origin'])->toBe('ai')->and($f['quote_verified'])->toBeTrue()->and($f['source']['type'])->toBe('answer')->and($f['source']['question_id'])->toBe($this->question->id);
    expect(json_encode($d))->not->toContain('text_content')->not->toContain('file_path');
});

it('returns reviewer-friendly criterion explanations with their linked question and answer', function () {
    $this->round->update(['scoring_criteria' => [
        ['name' => 'Impact', 'description' => 'Assess the business answer. Based on: Q1 "Describe your business".', 'weight' => 60, 'score_range' => '0-10'],
        ['name' => 'Viability', 'description' => 'Assess viability. Based on: Q1 "Describe your business".', 'weight' => 40, 'score_range' => '0-10'],
    ]]);

    $data = $this->actingAsOrgOwner()->getJson($this->base)->assertOk()->json('data');
    $impact = collect($data['criteria_reviews'])->firstWhere('criterion', 'Impact');

    expect((float) $impact['score'])->toBe(8.0)
        ->and((float) $impact['max_score'])->toBe(10.0)
        ->and((float) $impact['weight_percent'])->toBe(60.0)
        ->and((float) $impact['weighted_points'])->toBe(48.0)
        ->and($impact['rationale'])->toBe('Serves 200 farmers')
        ->and($impact['question']['id'])->toBe($this->question->id)
        ->and($impact['question']['text'])->toBe('Describe your business')
        ->and($impact['question']['answer'])->toBe(self::ANSWER)
        ->and($data['questions_and_answers'][0]['answered'])->toBeTrue();
});

it('lets an assigned reviewer read it but not an unassigned one, another organization or an applicant', function () {
    $this->actingAsReviewer()->getJson($this->base)->assertOk();
    \App\Models\Programs\Rounds\ReviewerApplicationAssignment::query()->delete();
    $this->actingAsReviewer()->getJson($this->base)->assertStatus(403);
    $this->actingAs($this->otherOrgUser(), 'sanctum')->getJson($this->base)->assertStatus(403);
    $this->actingAsApplicant()->getJson($this->base)->assertStatus(403);
});

it('returns 404 for a manual round', function () {
    $this->round->update(['review_method' => 'manual']);
    $this->actingAsOrgOwner()->getJson($this->base)->assertStatus(404);
});

it('lets a reviewer add a human finding that is labelled human', function () {
    $res = $this->actingAsReviewer()->postJson("{$this->base}/findings", ['dimension' => 'traction', 'polarity' => 'risk', 'title' => 'Site visit needed', 'body' => 'I could not verify the customers.'])->assertCreated();
    expect($res->json('data.origin'))->toBe('human')->and($res->json('data.author_user_id'))->toBe($this->reviewerUser->id);
    $findings = $this->actingAsOrgOwner()->getJson($this->base)->json('data.findings');
    expect(collect($findings)->pluck('origin')->all())->toBe(['ai', 'human']);
});

it('confirms, dismisses and overrides a finding while preserving the original AI text', function () {
    $f = ApplicationAiFinding::where('author_type', 'ai')->first();
    $url = "/api/v1/programs/ai-findings/{$f->id}";

    $this->actingAsReviewer()->patchJson($url, ['action' => 'confirm'])->assertOk()->assertJsonPath('data.status', 'confirmed');
    $this->actingAsReviewer()->patchJson($url, ['action' => 'dismiss'])->assertOk()->assertJsonPath('data.status', 'dismissed');
    $this->actingAsReviewer()->patchJson($url, ['action' => 'override', 'body' => 'New wording'])->assertStatus(422);
    $res = $this->actingAsReviewer()->patchJson($url, ['action' => 'override', 'body' => 'Revenue grew 40% (verified)', 'reviewer_note' => 'Checked the accounts'])->assertOk();
    expect($res->json('data.status'))->toBe('overridden')->and($res->json('data.body'))->toBe('Revenue grew 40% (verified)')->and($res->json('data.original_body'))->toBe('Revenue grew by 40%.')
        ->and($res->json('data.reviewed_by'))->toBe($this->reviewerUser->id);
    $res2 = $this->actingAsReviewer()->patchJson($url, ['action' => 'override', 'body' => 'Third wording', 'reviewer_note' => 'again'])->assertOk();
    expect($res2->json('data.original_body'))->toBe('Revenue grew by 40%.');
    expect(DB::table('ai_review_audit_logs')->where('action', 'finding_reviewed')->count())->toBe(4);
});

it('forbids changing findings for strangers and after the round is finalized', function () {
    $f = ApplicationAiFinding::first();
    $this->actingAs($this->otherOrgUser(), 'sanctum')->patchJson("/api/v1/programs/ai-findings/{$f->id}", ['action' => 'confirm'])->assertStatus(403);
    $this->actingAsApplicant()->patchJson("/api/v1/programs/ai-findings/{$f->id}", ['action' => 'confirm'])->assertStatus(403);
    $this->round->update(['status' => 'finalized']);
    $this->actingAsOrgOwner()->patchJson("/api/v1/programs/ai-findings/{$f->id}", ['action' => 'confirm'])->assertStatus(422);
});

it('gives the owner a round overview with capacity, provider and status counts', function () {
    $res = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews")->assertOk();
    $d = $res->json('data');
    expect($d['review_method'])->toBe('ai_assisted')->and($d['counts']['ready'])->toBe(1)->and($d['provider'])->toBe('groq')->and($d['privacy_gate_satisfied'])->toBeTrue()
        ->and($d['capacity']['requests_remaining_today'])->toBeGreaterThan(0)->and($d['applications'][0]['status'])->toBe('ready');
    $this->actingAsReviewer()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews")->assertStatus(403);
});

it('shows only the latest AI review attempt for each application in round activity', function () {
    $old = \App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $this->application->id)->firstOrFail();
    $latest = $old->replicate();
    $latest->input_hash = hash('sha256', $old->input_hash.'latest-attempt');
    $latest->status = 'needs_attention';
    $latest->error = 'Latest attempt needs attention.';
    $latest->completed_at = now();
    $latest->save();

    $data = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->assertOk()->json('data');

    expect($data['recent'])->toHaveCount(1)
        ->and($data['recent'][0]['status'])->toBe('needs_attention')
        ->and($data['recent'][0]['error'])->toBe('Latest attempt needs attention.')
        ->and($data['counts']['attention'])->toBe(1)
        ->and($data['counts']['done'])->toBe(0);
});

it('lets only the owner rerun a review, and audits it', function () {
    $this->actingAsReviewer()->postJson("{$this->base}/rerun")->assertStatus(403);
    $this->actingAsOrgOwner()->postJson("{$this->base}/rerun")->assertStatus(202);
    expect(DB::table('ai_review_audit_logs')->where('action', 'rerun')->exists())->toBeTrue();
    $this->round->update(['review_method' => 'manual']);
    $this->actingAsOrgOwner()->postJson("{$this->base}/rerun")->assertStatus(422);
});

it('never leaks AI data in any applicant-facing response', function () {
    foreach (["/api/v1/programs/applications/{$this->application->id}", '/api/v1/programs/my-applications', "/api/v1/programs/applications/{$this->application->id}/rounds", "/api/v1/programs/{$this->program->id}/sme/applications"] as $url) {
        $body = json_encode($this->actingAsApplicant()->getJson($url)->json());
        foreach (['Revenue grew', 'suggested_scores', 'deterministic_checks', 'application_ai', 'Prefer rural impact', 'quote_verified', 'ai_review_config'] as $secret) {
            expect($body)->not->toContain($secret);
        }
    }
});

it('tells the owner what the AI is doing for the round: counts, the finished timeline and an estimate, in a small answer', function () {
    $res = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->assertOk();
    $d = $res->json('data');

    expect($d['counts']['total'])->toBe(1)->and($d['counts']['done'])->toBe(1)->and($d['counts']['waiting'])->toBe(0)
        ->and($d['state'])->toBe('done')
        ->and($d['now'])->toBeNull()->and($d['queue'])->toBe([])
        ->and($d['recent'])->toHaveCount(1)->and($d['recent'][0]['application_id'])->toBe($this->application->id)
        ->and($d['recent'][0]['status'])->toBe('ready')->and($d['recent'][0]['completed_at'])->not->toBeNull()
        ->and(json_encode($d))->not->toContain('text_content');
});

it('lists waiting reviews with the latest first and counts the ones not shown', function () {
    $review = \App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $this->application->id)->first();
    $review->update(['status' => 'queued', 'completed_at' => null, 'started_at' => null]);

    $d = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->assertOk()->json('data');

    expect($d['state'])->toBeIn(['starting', 'paused'])->and($d['counts']['waiting'])->toBe(1)
        ->and($d['queue'])->toHaveCount(1)->and($d['queue'][0]['queued_at'])->not->toBeNull()->and($d['queue_more'])->toBe(0);
});

it('keeps the activity of a round away from people who do not own the program', function () {
    $this->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->assertStatus(401);
});

it('carries on with waiting reviews without re-scoring a finished one', function () {
    $review = \App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $this->application->id)->first();
    $before = $review->completed_at;

    $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/resume")->assertStatus(202)->assertJsonPath('data.started', 0);
    expect($review->fresh()->status)->toBe('ready')->and($review->fresh()->completed_at->equalTo($before))->toBeTrue();

    $review->update(['status' => 'queued']);
    $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/resume")->assertStatus(202)->assertJsonPath('data.started', 1);
});

it('does not let a stranger resume the AI of a round', function () {
    $this->postJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/resume")->assertStatus(401);
});
