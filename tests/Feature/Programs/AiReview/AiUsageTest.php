<?php

use App\Models\AiUsageLog;
use App\Service\AiReview\AiReviewDispatcher;
use Tests\Feature\Programs\AiReview\AiReviewTestCase;

uses(AiReviewTestCase::class);

it('records each provider call for the authenticated review initiator and exposes it immediately', function () {
    $this->aiRound();
    $this->llm->default = $this->report();
    $this->actingAsOrgOwner();
    app(AiReviewDispatcher::class)->dispatchFor($this->application);

    expect(AiUsageLog::count())->toBe(count($this->llm->requests))
        ->and(AiUsageLog::first()->user_id)->toBe($this->orgUser->id)
        ->and(AiUsageLog::first()->application_id)->toBe($this->application->id);

    $this->getJson('/api/v1/programs/ai/usage/me')->assertOk()->assertJsonPath('success', true)
        ->assertJsonPath('data.lifetime.total_requests', count($this->llm->requests));
});

it('uses provider request IDs to avoid duplicate billing records and preserves the price snapshot', function () {
    config(['ai_review.pricing.groq.fake-model' => ['input_per_million' => '0.15', 'cached_per_million' => '0.075', 'output_per_million' => '0.60', 'currency' => 'USD']]);
    $context = ['user_id' => $this->orgUser->id, 'operation' => 'review'];
    $response = ['provider' => 'groq', 'model' => 'fake-model', 'request_id' => 'req-usage-idempotent', 'usage' => [
        'prompt_tokens' => 1000, 'completion_tokens' => 500, 'total_tokens' => 1500, 'prompt_tokens_details' => ['cached_tokens' => 200],
    ]];
    $first = app(\App\Service\AiReview\AiUsageTrackingService::class)->recordUsage($context, $response);
    $again = app(\App\Service\AiReview\AiUsageTrackingService::class)->recordUsage($context, $response);
    expect(AiUsageLog::count())->toBe(1)->and($again->id)->toBe($first->id)
        ->and($first->total_cost)->toBe('0.000435000000')
        ->and($first->pricing_snapshot['input_per_million'])->toBe('0.15');
});
