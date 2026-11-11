<?php

use App\Service\AiReview\LlmException;
use App\Service\AiReview\LlmRequest;
use App\Service\AiReview\OpenAiCompatibleClient;
use Illuminate\Support\Facades\Http;

function client(array $cfg = []): OpenAiCompatibleClient
{
    return new OpenAiCompatibleClient('test', $cfg + ['base_url' => 'https://llm.test/v1', 'api_key' => 'k', 'model' => 'm1', 'max_output_tokens' => 500, 'supports_json_mode' => true, 'supports_json_schema' => false]);
}
function ask() { return new LlmRequest([['role' => 'user', 'content' => 'hi']], ['type' => 'object'], 100); }

it('returns parsed JSON and token usage', function () {
    Http::fake(['llm.test/*' => Http::response(['model' => 'm1', 'choices' => [['message' => ['content' => '{"a":1}'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4]])]);
    $r = client()->complete(ask());
    expect($r->json)->toBe(['a' => 1])->and($r->tokensIn)->toBe(10)->and($r->tokensOut)->toBe(4);
    Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer k') && $req['temperature'] === 0 && $req['response_format']['type'] === 'json_object');
});

it('strips code fences around JSON', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => "```json\n{\"a\":2}\n```"]]]])]);
    expect(client()->complete(ask())->json)->toBe(['a' => 2]);
});

it('classifies a 429 with Retry-After', function () {
    Http::fake(['*' => Http::response(['error' => 'slow down'], 429, ['Retry-After' => '17'])]);
    try { client()->complete(ask()); } catch (LlmException $e) {
        expect($e->kind)->toBe(LlmException::RATE_LIMITED)->and($e->retryAfter)->toBe(17)->and($e->retryable())->toBeTrue();
        return;
    }
    $this->fail('no exception');
});

it('classifies daily quota, auth, 5xx, model-not-found and bad request errors', function () {
    $cases = [
        [429, 'daily quota exceeded', LlmException::QUOTA_EXHAUSTED], [401, 'bad key', LlmException::AUTH_ERROR],
        [503, 'down', LlmException::SERVER_ERROR], [404, 'model not found', LlmException::MODEL_NOT_FOUND], [400, 'nope', LlmException::BAD_REQUEST],
    ];
    $seq = Http::sequence();
    foreach ($cases as [$status, $body]) $seq->push($body, $status);
    Http::fake(['*' => $seq]);
    foreach ($cases as [$status, $body, $kind]) {
        try { client()->complete(ask()); $this->fail('no exception'); } catch (LlmException $e) { expect($e->kind)->toBe($kind); }
    }
    expect((new LlmException(LlmException::AUTH_ERROR))->retryable())->toBeFalse();
});

it('uses json_schema when the provider supports it', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{}']]]])]);
    client(['supports_json_schema' => true])->complete(ask());
    Http::assertSent(fn ($req) => $req['response_format']['type'] === 'json_schema');
});
