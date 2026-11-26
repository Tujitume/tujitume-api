<?php

namespace App\Service\AiReview;

class AiPricingService
{
    /** Prices are USD per million tokens and can be overridden in config/ai_review.php. */
    public function calculate(string $provider, string $model, array $usage): array
    {
        $prices = config("ai_review.pricing.{$provider}.{$model}", config("ai_review.pricing.{$provider}.__default"));
        $input = isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null;
        $output = isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null;
        $cached = isset($usage['cached_tokens']) ? (int) $usage['cached_tokens'] : 0;
        if (! is_array($prices) || ($prices['active'] ?? true) !== true
            || (! empty($prices['effective_from']) && $prices['effective_from'] > now()->toDateString())
            || (! empty($prices['expires_at']) && $prices['expires_at'] < now()->toDateString())
            || $input === null || $output === null) {
            return ['input_cost' => null, 'output_cost' => null, 'cached_cost' => null, 'total_cost' => null, 'currency' => $prices['currency'] ?? 'USD', 'pricing_snapshot' => null];
        }
        $cached = min($input, $cached);
        $regular = max(0, $input - $cached);
        $unit = fn (int $tokens, string $key) => $this->cost($tokens, (string) ($prices[$key] ?? '0'));
        $inCost = $unit($regular, 'input_per_million');
        $cacheCost = $unit($cached, 'cached_per_million');
        $outCost = $unit($output, 'output_per_million');
        return ['input_cost' => $inCost, 'cached_cost' => $cacheCost, 'output_cost' => $outCost,
            'total_cost' => $this->add($inCost, $cacheCost, $outCost),
            'currency' => $prices['currency'] ?? 'USD', 'pricing_snapshot' => $prices];
    }

    /** Multiply decimal USD-per-million rates without converting money to binary floating point. */
    private function cost(int $tokens, string $rate): string
    {
        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');
        $rateMicros = ((int) $whole * 1000000) + (int) str_pad(substr($fraction, 0, 6), 6, '0');
        $scaled = $tokens * $rateMicros; // million token denominator + six rate decimals = 12 cost decimals
        $wholeCost = intdiv($scaled, 1000000000000);
        $fractionCost = $scaled % 1000000000000;
        return $wholeCost.'.'.str_pad((string) $fractionCost, 12, '0', STR_PAD_LEFT);
    }

    private function add(string ...$amounts): string
    {
        $whole = 0;
        $fraction = 0;
        foreach ($amounts as $amount) {
            [$units, $decimals] = array_pad(explode('.', $amount, 2), 2, '');
            $whole += (int) $units;
            $fraction += (int) str_pad(substr($decimals, 0, 12), 12, '0');
        }
        $whole += intdiv($fraction, 1000000000000);
        $fraction %= 1000000000000;
        return $whole.'.'.str_pad((string) $fraction, 12, '0', STR_PAD_LEFT);
    }
}
