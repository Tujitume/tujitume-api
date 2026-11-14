<?php

use App\Service\AiReview\PrivacyGate;
use App\Service\AiReview\ProviderRouter;
use Tests\Feature\Programs\AiReview\AiReviewTestCase;

uses(AiReviewTestCase::class);

beforeEach(function () {
    $this->aiRound('ai_assisted');
});

it('has both amari and groq set up as the same service (address and model)', function () {
    $amari = config('ai_review.providers.amari');
    $groq = config('ai_review.providers.groq');

    foreach ([$amari, $groq] as $cfg) {
        expect($cfg['driver'])->toBe('openai_compatible')->and($cfg['base_url'])->not->toBeEmpty()->and($cfg['model'])->not->toBeEmpty();
    }
    expect(parse_url($amari['base_url'], PHP_URL_HOST))->toBe(parse_url($groq['base_url'], PHP_URL_HOST))
        ->and($groq['model'])->toBe($amari['model']);
});

it('serves from whichever provider is the default, and falls back to the other', function () {
    foreach (['amari', 'groq'] as $name) {
        config(["ai_review.providers.{$name}.api_key" => 'test-key', "ai_review.providers.{$name}.allow_applicant_data" => true, "ai_review.providers.{$name}.trains_on_data" => false,
            "ai_review.providers.{$name}.limits" => ['rpm' => 1000, 'tpm' => 1000000, 'rpd' => 100000, 'reset_tz' => 'UTC']]);
    }
    $router = app(ProviderRouter::class);

    config(['ai_review.default_provider' => 'amari', 'ai_review.fallbacks' => []]);
    expect($router->pick($this->program, 100)['client']->provider())->toBe('amari');

    config(['ai_review.default_provider' => 'groq']);
    expect($router->pick($this->program, 100)['client']->provider())->toBe('groq');

    // The default is skipped (for example it is rate limited): the other one serves
    config(['ai_review.default_provider' => 'amari', 'ai_review.fallbacks' => ['groq']]);
    expect($router->pick($this->program, 100, ['amari'])['client']->provider())->toBe('groq');
});

it('does not ask the organization to accept again when it switches between providers that are the same service', function () {
    $gate = app(PrivacyGate::class);
    $org = $gate->organizationFor($this->program);
    config(['ai_review.providers.amari.allow_applicant_data' => false, 'ai_review.providers.groq.allow_applicant_data' => false]);

    $org->update(['ai_data_ack_at' => now(), 'ai_data_ack_provider' => 'amari']);
    expect($gate->satisfied('amari', $org->fresh()))->toBeTrue()->and($gate->satisfied('groq', $org->fresh()))->toBeTrue();

    // A provider at a different address still needs its own acceptance
    config(['ai_review.providers.cerebras.base_url' => 'https://api.cerebras.ai/v1']);
    expect($gate->satisfied('cerebras', $org->fresh()))->toBeFalse();
});
