<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Service\AI\AmariAccountContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Amari's gateway. The browser sends the conversation here; this server holds the AI key, picks the model and
 * settings (client overrides are ignored), adds the signed-in user's account block, and streams the answer back.
 */
class AmariController extends Controller
{
    private const GUARD_THRESHOLD = 0.5;

    // Setup check for the site: says only whether a key is configured, never the key.
    public function status(): JsonResponse
    {
        return response()->json(['ready' => (bool) config('services.amari.key')]);
    }

    public function chat(Request $request)
    {
        $messages = $request->input('messages');
        if (! is_array($messages) || ! $messages) {
            return $this->error('messages required', 400);
        }
        if (strlen(json_encode($messages)) > 200_000) {
            return $this->error('Conversation is too long.', 413);
        }
        if (! config('services.amari.key')) {
            return $this->error('Amari is not connected to an AI model yet.', 503);
        }

        $profile = in_array($request->input('profile'), ['public', 'debug'], true) ? $request->input('profile') : 'portal';
        $public = $profile === 'public';

        // The browser cannot supply an ACCOUNT block of its own: the only one is the server's, placed straight
        // after the leading system messages.
        $sent = array_values(array_filter($messages, fn ($m) => ! (
            ($m['role'] ?? null) === 'system' && is_string($m['content'] ?? null) && str_starts_with($m['content'], 'ACCOUNT ')
        )));
        $user = $public ? null : auth('sanctum')->user();
        $account = $user ? AmariAccountContext::messageFor($user) : null;
        if ($account) {
            $at = collect($sent)->search(fn ($m) => ($m['role'] ?? null) !== 'system');
            $at = $at === false ? count($sent) : $at;
            array_splice($sent, $at, 0, [['role' => 'system', 'content' => $account]]);
        }

        $model = $public ? config('services.amari.public_model') : config('services.amari.model');
        if ($wait = $this->cooldown($model)) {
            return $this->rateLimited($wait);
        }

        if ($public) {
            $question = collect($messages)->last(fn ($m) => ($m['role'] ?? null) === 'user')['content'] ?? null;
            if (! is_string($question) || trim($question) === '') {
                return $this->error('A question is required.', 400);
            }
            if (strlen($question) > 4096) {
                return $this->error('Please shorten your question before sending it.', 400);
            }
            if ($blocked = $this->guard($question)) {
                return $blocked;
            }
        }

        $stream = $request->input('stream') !== false;
        $body = [
            'model' => $model,
            'messages' => $sent,
            'stream' => $stream,
            'temperature' => 1,
            'max_completion_tokens' => $public ? 1024 : 2048,
            'top_p' => 1,
            'reasoning_effort' => $public ? 'low' : 'medium',
        ];

        try {
            $upstream = $this->upstream()->withOptions(['stream' => $stream])->timeout(90)->post(config('services.amari.url'), $body);
        } catch (ConnectionException) {
            return $this->error('upstream unavailable', 502);
        }

        if ($upstream->status() === 429) {
            $seconds = $this->retrySeconds($upstream->header('Retry-After'));
            Cache::put($this->cooldownKey($model), now()->addSeconds($seconds)->timestamp, $seconds);

            return $this->rateLimited($seconds);
        }
        if (! $upstream->successful()) {
            logger()->error('amari: upstream error', ['status' => $upstream->status(), 'model' => $model]); // never the prompt or key

            return $this->error('upstream unavailable', 502);
        }
        if (! $stream) {
            return response($upstream->body(), 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
        }

        $source = $upstream->toPsrResponse()->getBody();

        return new StreamedResponse(function () use ($source) {
            while (! $source->eof() && ! connection_aborted()) { // the client pressed Stop or left: stop paying for the answer
                echo $source->read(1024);
                @ob_flush();
                flush();
            }
            $source->close();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no', // nginx: do not buffer the stream
        ]);
    }

    // Prompt Guard check for signed-out visitors: long questions are scanned in overlapping windows. An additional
    // prompt check, not an authorization mechanism. Returns a response to send instead of answering, or null to go on.
    private function guard(string $question): ?JsonResponse
    {
        $guardModel = config('services.amari.guard_model');
        if ($wait = $this->cooldown($guardModel)) {
            return $this->rateLimited($wait);
        }
        $fingerprint = 'amari:guard:'.hash('sha256', $question);
        if (Cache::has($fingerprint)) {
            return null;
        }

        foreach ($this->guardSegments($question) as $segment) {
            try {
                $response = $this->upstream()->timeout(20)->post(config('services.amari.url'), [
                    'model' => $guardModel,
                    'messages' => [['role' => 'user', 'content' => $segment]],
                    'temperature' => 1, 'max_completion_tokens' => 1, 'top_p' => 1, 'stream' => false,
                ]);
            } catch (ConnectionException) {
                return $this->error('The prompt check is unavailable. Please try again.', 503);
            }
            if ($response->status() === 429) {
                $seconds = $this->retrySeconds($response->header('Retry-After'));
                Cache::put($this->cooldownKey($guardModel), now()->addSeconds($seconds)->timestamp, $seconds);

                return $this->rateLimited($seconds);
            }
            $score = $response->json('choices.0.message.content');
            if (! $response->successful() || ! is_numeric($score) || $score < 0 || $score > 1) {
                return $this->error('The prompt check is unavailable. Please try again.', 503);
            }
            if ((float) $score >= self::GUARD_THRESHOLD) {
                return response()->json(['error' => ['code' => 'prompt_blocked', 'message' => 'Please rephrase your question about Tujitume.']], 403);
            }
        }

        Cache::put($fingerprint, 1, 300);

        return null;
    }

    // Windows of at most 384 bytes (the guard reads 512 tokens) that overlap by 32 characters, so an instruction
    // split at a boundary is still inspected together.
    private function guardSegments(string $text): array
    {
        $segments = [];
        $chars = [];
        $bytes = 0;
        foreach (mb_str_split($text) as $char) {
            $size = strlen($char);
            if ($bytes + $size > 384) {
                $segments[] = implode('', $chars);
                $chars = array_slice($chars, -32);
                $bytes = strlen(implode('', $chars));
            }
            $chars[] = $char;
            $bytes += $size;
        }
        if ($chars) {
            $segments[] = implode('', $chars);
        }

        return $segments;
    }

    private function upstream()
    {
        return Http::withToken(config('services.amari.key'))->acceptJson()->withHeaders(['X-Title' => 'Amari']);
    }

    private function cooldownKey(string $model): string
    {
        return 'amari:cooldown:'.$model;
    }

    private function cooldown(string $model): int
    {
        $until = (int) Cache::get($this->cooldownKey($model), 0);

        return max(0, $until - now()->timestamp);
    }

    private function retrySeconds(?string $value): int
    {
        if ($value !== null && is_numeric($value) && $value > 0) {
            return (int) ceil($value);
        }
        $date = $value ? strtotime($value) : false;

        return $date && $date > time() ? $date - time() : 60;
    }

    private function rateLimited(int $seconds): JsonResponse
    {
        return response()->json(
            ['error' => ['message' => 'Too many requests. Please wait before asking again.'], 'retry_after_seconds' => $seconds],
            429,
            ['Retry-After' => (string) $seconds],
        );
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['message' => $message]], $status);
    }
}
