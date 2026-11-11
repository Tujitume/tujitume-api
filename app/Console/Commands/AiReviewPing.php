<?php

namespace App\Console\Commands;

use App\Service\AiReview\LlmException;
use App\Service\AiReview\LlmRequest;
use App\Service\AiReview\OpenAiCompatibleClient;
use Illuminate\Console\Command;

class AiReviewPing extends Command
{
    protected $signature = 'ai-review:ping {--no-live : Only check configuration, make no network call} {--provider= : Check a single provider}';
    protected $description = 'Check the AI provider configuration, then make a tiny live call per configured provider.';

    public function handle(): int
    {
        $rows = [];
        $names = $this->option('provider') ? [$this->option('provider')] : array_keys(config('ai_review.providers'));
        $active = array_filter([config('ai_review.default_provider'), ...config('ai_review.fallbacks', [])]);
        $this->line('Enabled: '.(config('ai_review.enabled') ? 'yes' : 'NO').' | default: '.config('ai_review.default_provider').' | fallbacks: '.(implode(',', config('ai_review.fallbacks', [])) ?: 'none'));

        foreach ($names as $name) {
            $cfg = config("ai_review.providers.{$name}");
            if (! $cfg) { $rows[] = [$name, 'unknown provider', '', '', '']; continue; }
            $configured = ! empty($cfg['base_url']) && ! empty($cfg['model']) && ! empty($cfg['api_key']);
            $limits = "{$cfg['limits']['rpm']} rpm / {$cfg['limits']['tpm']} tpm / {$cfg['limits']['rpd']} rpd";
            if (! $configured) { $rows[] = [$name.(in_array($name, $active) ? ' *' : ''), 'not configured (missing key, URL or model)', '-', $cfg['model'] ?? '-', $limits]; continue; }
            if ($this->option('no-live') || config('ai_review.external_calls_disabled')) { $rows[] = [$name.(in_array($name, $active) ? ' *' : ''), 'configured (no live call)', '-', $cfg['model'], $limits]; continue; }

            $start = microtime(true);
            try {
                $resp = (new OpenAiCompatibleClient($name, $cfg))->complete(new LlmRequest([['role' => 'user', 'content' => 'Reply with the JSON {"ok":true}']], ['type' => 'object'], 400));
                $ms = (int) ((microtime(true) - $start) * 1000);
                $rows[] = [$name.(in_array($name, $active) ? ' *' : ''), 'OK', $ms.' ms', $resp->model, $limits];
            } catch (LlmException $e) {
                $rows[] = [$name.(in_array($name, $active) ? ' *' : ''), 'FAILED: '.$e->kind.($e->status ? " ({$e->status})" : ''), '-', $cfg['model'], $limits];
            }
        }

        $this->table(['Provider (* = in use)', 'Status', 'Latency', 'Model', 'Configured limits'], $rows);

        return self::SUCCESS;
    }
}
