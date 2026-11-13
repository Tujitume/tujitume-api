<?php

use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\Ai\ApplicationDocumentExtraction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Programs\AiReview\AiReviewTestCase;

uses(AiReviewTestCase::class);

it('ai-review:ping checks configuration without a network call', function () {
    Http::fake();
    $this->artisan('ai-review:ping', ['--no-live' => true])->expectsOutputToContain('configured (no live call)')->assertSuccessful();
    Http::assertNothingSent();
});

it('ai-review:ping proves a provider switch by env alone: a different URL, key and model are used', function () {
    config(['ai_review.default_provider' => 'custom', 'ai_review.providers.custom' => array_merge(config('ai_review.providers.custom'), [
        'base_url' => 'https://my-ollama.local/v1', 'api_key' => 'secret', 'model' => 'llama3.1:8b', 'supports_json_mode' => true,
    ])]);
    Http::fake(['my-ollama.local/*' => Http::response(['model' => 'llama3.1:8b', 'choices' => [['message' => ['content' => '{"ok":true}']]], 'usage' => []])]);
    $this->artisan('ai-review:ping', ['--provider' => 'custom'])->expectsOutputToContain('OK')->assertSuccessful();
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://my-ollama.local/v1/chat/completions') && $r['model'] === 'llama3.1:8b' && $r->hasHeader('Authorization', 'Bearer secret'));
});

it('ai-review uses the exact Amari connection without duplicating the chat path', function () {
    config(['ai_review.providers.amari' => array_merge(config('ai_review.providers.amari'), [
        'base_url' => 'https://amari.test/openai/v1', 'api_key' => 'amari-secret', 'model' => 'amari-model',
    ])]);
    Http::fake(['amari.test/*' => Http::response(['model' => 'amari-model', 'choices' => [['message' => ['content' => '{"ok":true}']]], 'usage' => []])]);

    $this->artisan('ai-review:ping', ['--provider' => 'amari'])->expectsOutputToContain('OK')->assertSuccessful();
    Http::assertSent(fn ($request) => $request->url() === 'https://amari.test/openai/v1/chat/completions'
        && $request['model'] === 'amari-model'
        && $request->hasHeader('Authorization', 'Bearer amari-secret'));
});

it('ai-review:ping reports failures per provider', function () {
    Http::fake(['*' => Http::response('bad', 401)]);
    $this->artisan('ai-review:ping', ['--provider' => 'groq'])->expectsOutputToContain('FAILED: auth_error')->assertSuccessful();
});

it('ai-review:smoke runs the pipeline and prints quality figures', function () {
    $this->aiRound('ai_assisted');
    $this->llm->default = $this->report();
    $this->artisan('ai-review:smoke', ['application' => $this->application->id])->expectsOutputToContain('verified_quote_ratio')->assertSuccessful();
    expect(ApplicationAiReview::first()->status)->toBe('ready');
});

it('ai-review:smoke refuses a manual round', function () {
    $this->artisan('ai-review:smoke', ['application' => $this->application->id])->assertFailed();
});

it('purges old extracted text but keeps findings and summaries', function () {
    $this->aiRound('ai_assisted');
    $this->llm->default = $this->report();
    app(\App\Service\AiReview\AiReviewDispatcher::class)->dispatchFor($this->application);
    ApplicationDocumentExtraction::create(['source_type' => 'round_document', 'source_id' => 1, 'file_hash' => 'h', 'status' => 'ok', 'text_content' => 'old text', 'expires_at' => now()->subDay()]);
    Artisan::call('ai-reviews:purge-extractions');
    expect(ApplicationDocumentExtraction::count())->toBe(0)->and(ApplicationAiReview::first()->findings()->count())->toBe(1)->and(ApplicationAiReview::first()->summary)->not->toBeNull();
});

it('schedules the sweep hourly and the purge daily', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
    expect($events)->toContain('ai-reviews:sweep')->toContain('ai-reviews:purge-extractions');
});
