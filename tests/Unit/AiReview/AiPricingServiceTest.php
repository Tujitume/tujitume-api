<?php

use App\Service\AiReview\AiPricingService;

it('calculates regular input, cached input and output using decimal precision', function () {
    config(['ai_review.pricing.groq.m1' => ['input_per_million' => '0.15', 'cached_per_million' => '0.075', 'output_per_million' => '0.60', 'currency' => 'USD']]);
    $cost = app(AiPricingService::class)->calculate('groq', 'm1', ['input_tokens' => 1000, 'cached_tokens' => 200, 'output_tokens' => 500]);
    expect($cost['input_cost'])->toBe('0.000120000000')
        ->and($cost['cached_cost'])->toBe('0.000015000000')
        ->and($cost['output_cost'])->toBe('0.000300000000')
        ->and($cost['total_cost'])->toBe('0.000435000000');
});

it('leaves costs unknown when usage or model pricing is unavailable', function () {
    $service = app(AiPricingService::class);
    expect($service->calculate('unconfigured', 'm', ['input_tokens' => 10, 'output_tokens' => 3])['total_cost'])->toBeNull()
        ->and($service->calculate('groq', 'm', [])['total_cost'])->toBeNull();
});
