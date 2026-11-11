<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Checks Amari's OWN connection (config('services.amari'): AMARI_AI_API_KEY, AMARI_AI_URL, AMARI_AI_MODEL).
 * It is separate from the application-review AI (config('ai_review'): GROQ_API_KEY and friends); see ai-review:ping for that.
 */
class AmariPing extends Command
{
    protected $signature = 'amari:ping {--no-live : Only check the configuration, make no network call}';
    protected $description = "Check Amari's own AI connection (AMARI_AI_*), separate from the application-review AI.";

    public function handle(): int
    {
        $cfg = config('services.amari');
        $this->line('Amari uses: AMARI_AI_API_KEY ('.($cfg['key'] ? 'set' : 'MISSING').'), model '.$cfg['model'].', url '.$cfg['url']);

        if (! $cfg['key']) {
            $this->error('Amari is not connected: AMARI_AI_API_KEY is empty, so /amari/chat answers 503 and the app shows "not connected". Set it in .env and run php artisan config:clear.');

            return self::FAILURE;
        }
        if ($this->option('no-live')) {
            $this->info('Configured (no live call).');

            return self::SUCCESS;
        }

        $start = microtime(true);
        $response = Http::withToken($cfg['key'])->acceptJson()->timeout(30)->post($cfg['url'], [
            'model' => $cfg['model'], 'messages' => [['role' => 'user', 'content' => 'Reply with OK']], 'max_completion_tokens' => 200, 'reasoning_effort' => 'low',
        ]);
        $ms = (int) ((microtime(true) - $start) * 1000);

        if ($response->successful()) {
            $this->info("Amari's AI answered in {$ms} ms (model ".($response->json('model') ?? $cfg['model']).').');

            return self::SUCCESS;
        }
        $this->error('Amari\'s AI refused the call: HTTP '.$response->status().($response->status() === 401 ? ' (the key was rejected)' : ($response->status() === 429 ? ' (rate limited: the key may be shared with something else)' : '')));

        return self::FAILURE;
    }
}
