<?php

use App\Models\Communication\Notifications;
use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Service\AiReview\AiReviewDispatcher;
use App\Service\AiReview\AiReviewRunner;
use App\Service\AiReview\LlmException;
use Tests\Feature\Programs\AiReview\AiReviewTestCase;
use Tests\Feature\Programs\AiReview\FakeLlmClient;

uses(AiReviewTestCase::class);

function runReview($t, bool $force = false)
{
    $review = app(AiReviewDispatcher::class)->dispatchFor($t->application, null, $force);

    return $review->fresh();
}

it('waits instead of failing when the free quota is used up', function () {
    $this->aiRound('ai_assisted');
    $this->llm->push(new LlmException(LlmException::QUOTA_EXHAUSTED, 'daily quota', 600));
    $review = ApplicationAiReview::create(['application_id' => $this->application->id, 'round_id' => $this->round->id, 'program_id' => $this->program->id, 'mode' => 'ai_assisted', 'status' => 'queued', 'input_hash' => app(\App\Service\AiReview\ReviewInputBuilder::class)->quickHash($this->application, $this->round)]);

    $result = app(AiReviewRunner::class)->run($review);
    expect($result['release'])->toBeGreaterThanOrEqual(600)
        ->and($review->fresh()->status)->toBe('queued')->and($review->fresh()->attempts)->toBe(0)
        ->and($review->fresh()->deterministic_checks)->not->toBeEmpty()          // the free checks are kept
        ->and($this->application->fresh()->round_status)->toBe('submitted');
});

it('fails over to a fallback provider when the first is rate limited', function () {
    $this->aiRound('ai_assisted');
    config(['ai_review.fallbacks' => ['cerebras'], 'ai_review.providers.cerebras.api_key' => 'k', 'ai_review.providers.cerebras.allow_applicant_data' => true, 'ai_review.providers.cerebras.trains_on_data' => false,
        'ai_review.providers.cerebras.limits' => ['rpm' => 1000, 'tpm' => 1000000, 'rpd' => 100000, 'reset_tz' => 'UTC'], 'ai_review.providers.cerebras.context_tokens' => 200000]);
    $backup = new FakeLlmClient('cerebras', 'backup-model');
    app()->instance('ai_review.client.cerebras', $backup);
    $this->llm->push(new LlmException(LlmException::RATE_LIMITED, 'slow', 60));
    $backup->push($this->report());

    $review = runReview($this);
    expect($review->status)->toBe('ready')->and($review->provider)->toBe('cerebras')->and($review->model)->toBe('backup-model');
});

it('keeps only the automatic checks and flags the review when every provider is down', function () {
    $this->aiRound('ai_assisted');
    $this->llm->push(new LlmException(LlmException::SERVER_ERROR, 'down'));
    $review = runReview($this);
    expect($review->status)->toBe('needs_attention')->and($review->deterministic_checks)->not->toBeEmpty()->and($review->findings()->count())->toBe(0);
});

it('does nothing external when external calls are disabled (deterministic-only)', function () {
    $this->aiRound('ai_assisted');
    config(['ai_review.external_calls_disabled' => true]);
    $review = runReview($this);
    expect($review->status)->toBe('needs_attention')->and($this->llm->requests)->toBe([])->and($review->deterministic_checks)->not->toBeEmpty();
});

it('tells the owner once about an invalid key instead of retrying forever', function () {
    $this->aiRound('ai_assisted');
    $this->llm->default = new LlmException(LlmException::AUTH_ERROR, 'bad key');
    $first = runReview($this);
    expect($first->status)->toBe('needs_attention')->and($first->error)->toContain('credentials');
    $before = Notifications::where('receiver_id', $this->orgUser->id)->where('text', 'like', '%needs attention%')->count();
    expect($before)->toBe(1);

    \Illuminate\Support\Facades\Cache::forget('ai:groq:cooldown');
    runReview($this, true);
    expect(Notifications::where('receiver_id', $this->orgUser->id)->where('text', 'like', '%needs attention%')->count())->toBe(1);
});

it('never fails the application: a hard failure leaves the round flow untouched', function () {
    $this->aiRound('ai_only');
    $this->llm->default = new LlmException(LlmException::BAD_REQUEST, 'refused');
    runReview($this);
    expect(ApplicationScore::count())->toBe(0)->and($this->application->fresh()->status)->toBe('pending')->and($this->application->fresh()->round_status)->toBe('submitted');
});

it('rate limits itself: when our own per-minute cap is hit the job waits and no provider call is made', function () {
    $this->aiRound('ai_assisted');
    config(['ai_review.providers.groq.limits' => ['rpm' => 0, 'tpm' => 1000000, 'rpd' => 100, 'reset_tz' => 'UTC']]);
    $review = ApplicationAiReview::create(['application_id' => $this->application->id, 'round_id' => $this->round->id, 'program_id' => $this->program->id, 'mode' => 'ai_assisted', 'status' => 'queued', 'input_hash' => app(\App\Service\AiReview\ReviewInputBuilder::class)->quickHash($this->application, $this->round)]);
    $result = app(AiReviewRunner::class)->run($review);
    expect($result['release'])->toBeGreaterThan(0)->and($this->llm->requests)->toBe([]);
});

it('sends no applicant data to a provider that fails the privacy gate', function () {
    $this->aiRound('ai_assisted');
    config(['ai_review.providers.groq.allow_applicant_data' => false, 'ai_review.providers.groq.trains_on_data' => 'unknown']);
    $this->llm->default = $this->report();
    $review = runReview($this);
    expect($review->status)->toBe('needs_attention')->and($this->llm->requests)->toBe([]);

    // acknowledged by the organization: now allowed
    $this->organization->update(['ai_data_ack_at' => now(), 'ai_data_ack_by' => $this->orgUser->id, 'ai_data_ack_provider' => 'groq']);
    $this->orgUser->update(['organization_id' => $this->organization->id]);
    runReview($this, true);
    expect(count($this->llm->requests))->toBeGreaterThan(0);
});

it('stops retrying after the attempt limit and marks the review failed', function () {
    $this->aiRound('ai_assisted');
    config(['ai_review.retries' => 2]);
    $this->llm->default = ['summary' => 'never valid'];
    $review = ApplicationAiReview::create(['application_id' => $this->application->id, 'round_id' => $this->round->id, 'program_id' => $this->program->id, 'mode' => 'ai_assisted', 'status' => 'queued', 'input_hash' => app(\App\Service\AiReview\ReviewInputBuilder::class)->quickHash($this->application, $this->round)]);
    $runner = app(AiReviewRunner::class);
    expect($runner->run($review)['release'])->toBeGreaterThan(0);
    expect($review->fresh()->status)->toBe('queued');
    expect($runner->run($review->fresh())['release'])->toBeNull();
    expect($review->fresh()->status)->toBe('failed')->and($review->fresh()->attempts)->toBe(2);
});

it('skips a provider in cooldown and uses the next one', function () {
    $this->aiRound('ai_assisted');
    config(['ai_review.fallbacks' => ['cerebras'], 'ai_review.providers.cerebras.api_key' => 'k', 'ai_review.providers.cerebras.allow_applicant_data' => true, 'ai_review.providers.cerebras.trains_on_data' => false,
        'ai_review.providers.cerebras.limits' => ['rpm' => 1000, 'tpm' => 1000000, 'rpd' => 100000, 'reset_tz' => 'UTC'], 'ai_review.providers.cerebras.context_tokens' => 200000]);
    $backup = new FakeLlmClient('cerebras');
    $backup->default = $this->report();
    app()->instance('ai_review.client.cerebras', $backup);
    app(\App\Service\AiReview\RateGovernor::class)->cooldown('groq', 300);
    $review = runReview($this);
    expect($review->provider)->toBe('cerebras')->and($this->llm->requests)->toBe([]);
});
