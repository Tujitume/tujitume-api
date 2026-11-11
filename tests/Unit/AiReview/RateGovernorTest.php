<?php

use App\Service\AiReview\RateGovernor;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config(['ai_review.providers.groq.limits' => ['rpm' => 2, 'tpm' => 1000, 'rpd' => 3, 'reset_tz' => 'UTC']]);
});

it('enforces requests per minute and tells the caller how long to wait', function () {
    $g = new RateGovernor();
    expect($g->reserve('groq', 100)['ok'])->toBeTrue()->and($g->reserve('groq', 100)['ok'])->toBeTrue();
    $third = $g->reserve('groq', 100);
    expect($third['ok'])->toBeFalse()->and($third['reason'])->toBe('requests_per_minute')->and($third['retry_after'])->toBeGreaterThan(0)->toBeLessThanOrEqual(62);
});

it('enforces tokens per minute and refuses a request that can never fit', function () {
    $g = new RateGovernor();
    expect($g->reserve('groq', 900)['ok'])->toBeTrue();
    expect($g->reserve('groq', 200)['reason'])->toBe('tokens_per_minute');
    expect($g->reserve('groq', 5000)['reason'])->toBe('too_large');
});

it('enforces the daily request cap and reports the wait until the reset', function () {
    config(['ai_review.providers.groq.limits' => ['rpm' => 100, 'tpm' => 100000, 'rpd' => 3, 'reset_tz' => 'UTC']]);
    $g = new RateGovernor();
    foreach ([1, 2, 3] as $_) expect($g->reserve('groq', 10)['ok'])->toBeTrue();
    $r = $g->reserve('groq', 10);
    expect($r['ok'])->toBeFalse()->and($r['reason'])->toBe('daily_requests')->and($r['retry_after'])->toBeGreaterThan(59);
    expect($g->capacity('groq')['requests_remaining_today'])->toBe(0);
});

it('gives a reservation back and tracks cooldowns', function () {
    $g = new RateGovernor();
    $g->reserve('groq', 100);
    $g->refund('groq', 100);
    expect($g->capacity('groq')['requests_remaining_today'])->toBe(3);
    $g->cooldown('groq', 120);
    expect($g->cooldownRemaining('groq'))->toBeGreaterThan(100);
});
