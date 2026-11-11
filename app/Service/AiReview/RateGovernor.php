<?php

namespace App\Service\AiReview;

use App\Models\Programs\Ai\AiProviderUsage;
use Illuminate\Support\Facades\Cache;

/**
 * Enforces requests/minute, tokens/minute and requests/day per provider ourselves (free tiers are small).
 * Counters live in the cache store (Redis in production); the database keeps the audit trail.
 */
class RateGovernor
{
    private function store()
    {
        return Cache::store(config('ai_review.cache_store'));
    }

    private function limits(string $provider): array
    {
        return config("ai_review.providers.{$provider}.limits", ['rpm' => 30, 'tpm' => 10000, 'rpd' => 500, 'reset_tz' => 'UTC']);
    }

    private function now(string $provider)
    {
        return now($this->limits($provider)['reset_tz'] ?? 'UTC');
    }

    private function keys(string $provider): array
    {
        $now = $this->now($provider);

        return [
            'rpm' => "ai:{$provider}:rpm:".$now->format('YmdHi'),
            'tpm' => "ai:{$provider}:tpm:".$now->format('YmdHi'),
            'rpd' => "ai:{$provider}:rpd:".$now->format('Ymd'),
        ];
    }

    /**
     * Try to reserve one request and an estimated token amount.
     * @return array{ok:bool, retry_after:int, reason:?string}
     */
    public function reserve(string $provider, int $estimatedTokens): array
    {
        $limits = $this->limits($provider);
        $keys = $this->keys($provider);
        $now = $this->now($provider);
        $store = $this->store();

        $rpm = (int) $store->get($keys['rpm'], 0);
        $tpm = (int) $store->get($keys['tpm'], 0);
        $rpd = (int) $store->get($keys['rpd'], 0);

        if ($rpd >= $limits['rpd']) {
            return ['ok' => false, 'retry_after' => max(60, $now->copy()->addDay()->startOfDay()->diffInSeconds($now)), 'reason' => 'daily_requests'];
        }
        $untilNextMinute = max(1, 60 - (int) $now->format('s'));
        if ($rpm >= $limits['rpm']) {
            return ['ok' => false, 'retry_after' => $untilNextMinute + 1, 'reason' => 'requests_per_minute'];
        }
        if ($estimatedTokens > $limits['tpm']) {
            // A request that can never fit is not a wait: the caller must shrink it
            return ['ok' => false, 'retry_after' => 0, 'reason' => 'too_large'];
        }
        if ($tpm + $estimatedTokens > $limits['tpm']) {
            return ['ok' => false, 'retry_after' => $untilNextMinute + 1, 'reason' => 'tokens_per_minute'];
        }

        $this->bump($store, $keys['rpm'], 1, 120);
        $this->bump($store, $keys['tpm'], $estimatedTokens, 120);
        $this->bump($store, $keys['rpd'], 1, 172800);

        return ['ok' => true, 'retry_after' => 0, 'reason' => null];
    }

    /** After the call: replace the estimate with the real token count. */
    public function reconcile(string $provider, int $estimated, int $actual, ?string $model = null, ?int $reviewId = null, ?int $organizationId = null, int $tokensIn = 0, int $tokensOut = 0): void
    {
        $keys = $this->keys($provider);
        $delta = $actual - $estimated;
        if ($delta !== 0) {
            $this->bump($this->store(), $keys['tpm'], $delta, 120);
        }

        AiProviderUsage::create([
            'provider' => $provider, 'model' => $model, 'date' => $this->now($provider)->toDateString(), 'requests' => 1,
            'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut, 'organization_id' => $organizationId, 'review_id' => $reviewId,
        ]);
    }

    /** A reserved call that never reached the provider: give the reservation back. */
    public function refund(string $provider, int $estimatedTokens): void
    {
        $keys = $this->keys($provider);
        $store = $this->store();
        $this->bump($store, $keys['rpm'], -1, 120);
        $this->bump($store, $keys['rpd'], -1, 172800);
        $this->bump($store, $keys['tpm'], -$estimatedTokens, 120);
    }

    /** Remaining capacity today. */
    public function capacity(string $provider): array
    {
        $limits = $this->limits($provider);
        $keys = $this->keys($provider);
        $used = (int) $this->store()->get($keys['rpd'], 0);

        return ['requests_remaining_today' => max(0, $limits['rpd'] - $used), 'requests_per_day' => $limits['rpd'], 'rpm' => $limits['rpm'], 'tpm' => $limits['tpm']];
    }

    /** Seconds the provider is unavailable because of a server-reported limit (Retry-After). */
    public function cooldown(string $provider, int $seconds): void
    {
        $this->store()->put("ai:{$provider}:cooldown", now()->addSeconds($seconds)->timestamp, $seconds);
    }

    public function cooldownRemaining(string $provider): int
    {
        $until = (int) $this->store()->get("ai:{$provider}:cooldown", 0);

        return max(0, $until - now()->timestamp);
    }

    private function bump($store, string $key, int $by, int $ttl): void
    {
        $current = (int) $store->get($key, 0);
        $store->put($key, max(0, $current + $by), $ttl);
    }
}
