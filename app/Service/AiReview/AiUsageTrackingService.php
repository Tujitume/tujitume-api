<?php

namespace App\Service\AiReview;

use App\Models\AiUsageLog;
use Illuminate\Support\Facades\Log;

class AiUsageTrackingService
{
    public function __construct(private AiPricingService $pricing) {}

    public function recordUsage(array $context, array $providerResponse): ?AiUsageLog
    {
        $provider = (string) ($providerResponse['provider'] ?? $context['provider'] ?? 'unknown');
        $model = (string) ($providerResponse['model'] ?? '');
        $raw = $providerResponse['usage'] ?? null;
        $tokens = is_array($raw) ? [
            'input_tokens' => $raw['prompt_tokens'] ?? $raw['input_tokens'] ?? null,
            'output_tokens' => $raw['completion_tokens'] ?? $raw['output_tokens'] ?? null,
            'cached_tokens' => $raw['prompt_tokens_details']['cached_tokens'] ?? $raw['input_tokens_details']['cached_tokens'] ?? $raw['cached_tokens'] ?? null,
            'reasoning_tokens' => $raw['completion_tokens_details']['reasoning_tokens'] ?? $raw['output_tokens_details']['reasoning_tokens'] ?? null,
            'total_tokens' => $raw['total_tokens'] ?? null,
        ] : [];
        $priced = $this->pricing->calculate($provider, $model, $tokens);
        $id = $providerResponse['request_id'] ?? null;
        try {
            if ($id && ($existing = AiUsageLog::where('provider', $provider)->where('request_id', $id)->first())) return $existing;
            return AiUsageLog::create(array_merge(array_intersect_key($context, array_flip(['user_id','workspace_id','program_id','round_id','application_id','evaluation_run_id','operation'])), [
                'provider' => $provider, 'model' => $model ?: null, 'request_id' => $id, 'status' => $providerResponse['status'] ?? (is_array($raw) && $raw ? 'success' : 'unknown'),
                'input_tokens' => $tokens['input_tokens'] ?? null, 'output_tokens' => $tokens['output_tokens'] ?? null,
                'cached_tokens' => $tokens['cached_tokens'] ?? null, 'reasoning_tokens' => $tokens['reasoning_tokens'] ?? null,
                'total_tokens' => $tokens['total_tokens'] ?? (isset($tokens['input_tokens'], $tokens['output_tokens']) ? $tokens['input_tokens'] + $tokens['output_tokens'] : null),
                'input_cost' => $priced['input_cost'], 'output_cost' => $priced['output_cost'], 'cached_cost' => $priced['cached_cost'],
                'total_cost' => $priced['total_cost'], 'currency' => $priced['currency'], 'pricing_snapshot' => $priced['pricing_snapshot'],
                'cost_basis' => 'estimated',
                'duration_ms' => $providerResponse['duration_ms'] ?? null, 'error_message' => $providerResponse['error_message'] ?? null,
            ]));
        } catch (\Throwable $e) {
            Log::error('AI usage tracking failed', ['provider' => $provider, 'request_id' => $id, 'evaluation_run_id' => $context['evaluation_run_id'] ?? null, 'usage' => $raw, 'exception' => get_class($e)]);
            return null;
        }
    }
}
