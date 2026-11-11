<?php

use App\Models\Programs\Rounds\ProgramRound;
use App\Service\AiReview\PrivacyGate;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Programs\AiReview\AiReviewTestCase;

uses(AiReviewTestCase::class);

it('defaults to manual review and exposes it', function () {
    expect($this->round->fresh()->review_method)->toBe('manual');
    $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}")->assertOk()->assertJsonPath('data.review_method', 'manual');
});

it('lets the owner pick AI-assisted review on a draft round and stores the config', function () {
    $this->round->update(['status' => 'draft']);
    $this->patchRound(['review_method' => 'ai_assisted', 'ai_review_config' => ['dimensions' => ['traction'], 'priority_keywords' => ['rural'], 'instructions' => 'x']])->assertOk();
    $round = $this->round->fresh();
    expect($round->review_method)->toBe('ai_assisted')->and($round->ai_review_config['dimensions'])->toBe(['traction']);
    expect(DB::table('ai_review_audit_logs')->where('action', 'review_method_changed')->where('user_id', $this->orgUser->id)->exists())->toBeTrue();
});

it('requires explicit confirmation for AI-only and records who agreed', function () {
    $this->round->update(['status' => 'draft']);
    $this->patchRound(['review_method' => 'ai_only'])->assertStatus(422)->assertJsonValidationErrors('confirm_ai_only');
    $this->patchRound(['review_method' => 'ai_only', 'confirm_ai_only' => true, 'assignment_type' => 'internal'])->assertOk();
    $round = $this->round->fresh();
    expect($round->review_method)->toBe('ai_only')->and($round->assignment_type)->toBe('owner_only')
        ->and($round->ai_only_acknowledged_by)->toBe($this->orgUser->id)->and($round->ai_only_acknowledged_at)->not->toBeNull();
    expect(DB::table('ai_review_audit_logs')->where('action', 'ai_only_acknowledged')->exists())->toBeTrue();
});

it('locks the review method once the round is published', function () {
    // setUp publishes the round
    $this->patchRound(['review_method' => 'ai_assisted'])->assertStatus(422)->assertJsonValidationErrors('review_method');
    expect($this->round->fresh()->review_method)->toBe('manual');
});

it('validates the AI config', function () {
    $this->round->update(['status' => 'draft']);
    $this->patchRound(['ai_review_config' => ['dimensions' => ['nonsense']]])->assertStatus(422);
    $this->patchRound(['ai_review_config' => ['priority_keywords' => array_fill(0, 51, 'k')]])->assertStatus(422);
    $this->patchRound(['ai_review_config' => ['instructions' => str_repeat('x', 2001)]])->assertStatus(422);
    $this->patchRound(['ai_review_config' => ['desired_responses' => [['question_id' => 999999, 'expected' => 'x']]]])->assertStatus(422);
});

it('keeps the AI instructions and config away from applicants but shows the disclosure', function () {
    $this->aiRound('ai_assisted');
    $this->round->update(['status' => 'published']);
    $res = $this->actingAsApplicant()->getJson("/api/v1/programs/{$this->program->id}/sme/applications")->assertOk();
    $json = json_encode($res->json());
    expect($json)->not->toContain('Prefer rural impact')->not->toContain('ai_review_config')->not->toContain('priority_keywords');
    expect($res->json('application_round.ai_review_disclosure'))->toContain('third-party AI provider');
});

it('reports the publish checklist for AI rounds: config, provider and privacy gate', function () {
    $this->round->update(['status' => 'draft', 'review_method' => 'ai_assisted', 'ai_review_config' => null]);
    config(['ai_review.providers.groq.allow_applicant_data' => false, 'ai_review.providers.groq.trains_on_data' => 'unknown']);
    $res = $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/publish")->assertStatus(422);
    $errors = $res->json('errors');
    expect($errors)->toHaveKeys(['ai_review_config', 'ai_privacy']);

    config(['ai_review.enabled' => false]);
    expect($this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/publish")->json('errors'))->toHaveKey('ai_enabled');
    config(['ai_review.enabled' => true, 'ai_review.providers.groq.api_key' => null]);
    expect($this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/publish")->json('errors'))->toHaveKey('ai_provider');
});

it('satisfies the privacy gate with an acknowledgment and re-requires it when the provider changes', function () {
    config(['ai_review.providers.groq.allow_applicant_data' => false, 'ai_review.providers.groq.trains_on_data' => true]);
    $gate = app(PrivacyGate::class);
    $org = $this->organization;
    expect($gate->satisfied('groq', $org))->toBeFalse();

    $this->actingAsOrgOwner()->postJson('/api/v1/programs/ai-privacy-ack', [])->assertStatus(422);
    $this->actingAsOrgOwner()->postJson('/api/v1/programs/ai-privacy-ack', ['confirm' => true])->assertOk();
    $org->refresh();
    expect($org->ai_data_ack_provider)->toBe('groq')->and($org->ai_data_ack_by)->toBe($this->orgUser->id)->and($gate->satisfied('groq', $org))->toBeTrue();
    expect(DB::table('ai_review_audit_logs')->where('action', 'privacy_ack')->exists())->toBeTrue();

    config(['ai_review.default_provider' => 'mistral', 'ai_review.providers.mistral.api_key' => 'k']);
    expect($gate->satisfied('mistral', $org))->toBeFalse(); // another provider: acknowledge again
    $this->actingAsApplicant()->postJson('/api/v1/programs/ai-privacy-ack', ['confirm' => true])->assertStatus(403);
});
