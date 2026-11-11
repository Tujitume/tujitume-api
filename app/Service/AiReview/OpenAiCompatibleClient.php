<?php

namespace App\Service\AiReview;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** One chat-completions client for every provider; providers differ only by config. */
class OpenAiCompatibleClient implements LlmClient
{
    public function __construct(private string $name, private array $cfg)
    {
    }

    public function provider(): string { return $this->name; }

    public function model(): string { return (string) ($this->cfg['model'] ?? ''); }

    public function complete(LlmRequest $request): LlmResponse
    {
        $model = $request->vision && ! empty($this->cfg['vision_model']) ? $this->cfg['vision_model'] : $this->model();
        $messages = $request->messages;

        if ($request->images) {
            $last = array_pop($messages);
            $parts = [['type' => 'text', 'text' => is_array($last['content']) ? json_encode($last['content']) : (string) $last['content']]];
            foreach ($request->images as $image) {
                $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $image]];
            }
            $messages[] = ['role' => $last['role'], 'content' => $parts];
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0,
            'max_tokens' => min($request->maxTokens, (int) ($this->cfg['max_output_tokens'] ?? $request->maxTokens)),
        ];

        // Provider-specific fields from config (for example reasoning_effort)
        $payload = array_merge($payload, (array) ($this->cfg['extra'] ?? []));

        if ($request->jsonSchema && ! empty($this->cfg['supports_json_schema'])) {
            $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'review', 'strict' => false, 'schema' => $request->jsonSchema]];
        } elseif ($request->jsonSchema && ! empty($this->cfg['supports_json_mode'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        if ($request->jsonSchema && empty($this->cfg['supports_json_schema'])) {
            // Strict instruction for providers without schema support
            $payload['messages'][] = ['role' => 'user', 'content' => 'Reply with a single JSON object only. No prose, no code fences.'];
        }

        try {
            $response = Http::withToken((string) ($this->cfg['api_key'] ?? ''))
                ->acceptJson()
                ->connectTimeout((int) config('ai_review.timeouts.connect', 10))
                ->timeout((int) config('ai_review.timeouts.request', 90))
                ->post(rtrim((string) $this->cfg['base_url'], '/').'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            throw new LlmException(LlmException::SERVER_ERROR, 'Provider unreachable.');
        }

        if (! $response->successful()) {
            throw $this->classify($response->status(), (string) $response->body(), $response->header('Retry-After'));
        }

        $data = $response->json() ?? [];
        $text = (string) ($data['choices'][0]['message']['content'] ?? '');

        return new LlmResponse(
            $text,
            $this->parseJson($text),
            (int) ($data['usage']['prompt_tokens'] ?? 0),
            (int) ($data['usage']['completion_tokens'] ?? 0),
            $data['choices'][0]['finish_reason'] ?? null,
            $this->name,
            (string) ($data['model'] ?? $model),
        );
    }

    /** Tolerant JSON parsing: strips code fences and finds the outermost object. */
    public function parseJson(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') return null;
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) return $decoded;

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($decoded)) return $decoded;
        }

        return null;
    }

    private function classify(int $status, string $body, ?string $retryAfter): LlmException
    {
        $retry = $retryAfter !== null && is_numeric($retryAfter) ? (int) ceil((float) $retryAfter) : null;
        $lower = strtolower($body);

        return match (true) {
            $status === 429 && (str_contains($lower, 'quota') || str_contains($lower, 'per day') || str_contains($lower, 'daily'))
                => new LlmException(LlmException::QUOTA_EXHAUSTED, 'Provider quota exhausted.', $retry, $status),
            $status === 429 => new LlmException(LlmException::RATE_LIMITED, 'Provider rate limit reached.', $retry, $status),
            in_array($status, [401, 403], true) => new LlmException(LlmException::AUTH_ERROR, 'Provider rejected the API key.', null, $status),
            $status === 404 || (str_contains($lower, 'model') && (str_contains($lower, 'not found') || str_contains($lower, 'does not exist') || str_contains($lower, 'decommissioned')))
                => new LlmException(LlmException::MODEL_NOT_FOUND, 'Provider does not know this model.', null, $status),
            $status >= 500 => new LlmException(LlmException::SERVER_ERROR, 'Provider error.', $retry, $status),
            default => new LlmException(LlmException::BAD_REQUEST, 'Provider refused the request.', null, $status),
        };
    }
}
