<?php

use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

it('gives application review an Amari provider without changing the Groq provider', function () {
    $review = file_get_contents(config_path('ai_review.php'));
    $services = file_get_contents(config_path('services.php'));
    $amariBlock = substr($services, strpos($services, "'amari' => ["), 900);

    expect($review)->toContain("'default_provider' => env('AI_REVIEW_PROVIDER', 'amari')")
        ->toContain("'api_key' => env('AMARI_AI_API_KEY')")
        ->toContain("'model' => env('AMARI_AI_MODEL'")
        ->toContain("'api_key' => env('GROQ_API_KEY')")
        ->toContain("'model' => env('GROQ_MODEL'");
    expect($amariBlock)->toContain('AMARI_AI_API_KEY')->not->toContain('GROQ_')->not->toContain('AI_REVIEW_');
});

it('amari:ping uses only Amari\'s own key and url, never the review AI\'s', function () {
    config(['services.amari.key' => 'amari-key', 'services.amari.url' => 'https://amari.test/chat', 'ai_review.providers.groq.api_key' => 'review-key']);
    Http::fake(['amari.test/*' => Http::response(['model' => 'm', 'choices' => [['message' => ['content' => 'OK']]]])]);

    $this->artisan('amari:ping')->expectsOutputToContain('answered')->assertSuccessful();
    Http::assertSent(fn ($r) => $r->url() === 'https://amari.test/chat' && $r->hasHeader('Authorization', 'Bearer amari-key'));
});

it('amari:ping says plainly when Amari has no key', function () {
    config(['services.amari.key' => null, 'ai_review.providers.groq.api_key' => 'review-key']); // the review key must not stand in for it
    Http::fake();
    $this->artisan('amari:ping')->expectsOutputToContain('AMARI_AI_API_KEY is empty')->assertFailed();
    Http::assertNothingSent();
});
