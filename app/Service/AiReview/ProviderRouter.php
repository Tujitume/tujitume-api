<?php

namespace App\Service\AiReview;

use App\Models\Programs\Program;

/** Chooses which provider serves a call: default first, then fallbacks, skipping any that cannot be used now. */
class ProviderRouter
{
    public function __construct(private PrivacyGate $gate, private RateGovernor $governor)
    {
    }

    public function make(string $provider): LlmClient
    {
        $cfg = config("ai_review.providers.{$provider}");

        return app()->bound("ai_review.client.{$provider}") ? app("ai_review.client.{$provider}") : new OpenAiCompatibleClient($provider, $cfg);
    }

    /**
     * @return array{client:?LlmClient, wait:?int, reason:?string}
     *   client: usable now. Otherwise wait = seconds until some provider is likely free (null = none will become usable).
     */
    public function pick(Program $program, int $estimatedTokens, array $skip = []): array
    {
        if (config('ai_review.external_calls_disabled') || ! config('ai_review.enabled')) {
            return ['client' => null, 'wait' => null, 'reason' => 'disabled'];
        }

        $candidates = array_values(array_diff($this->gate->allowedProviders($program), $skip));
        if (! $candidates) {
            return ['client' => null, 'wait' => null, 'reason' => 'no_provider'];
        }

        $shortest = null;
        foreach ($candidates as $name) {
            if ($cool = $this->governor->cooldownRemaining($name)) {
                $shortest = $shortest === null ? $cool : min($shortest, $cool);
                continue;
            }
            $reserved = $this->governor->reserve($name, $estimatedTokens);
            if ($reserved['ok']) {
                return ['client' => $this->make($name), 'wait' => null, 'reason' => null];
            }
            if ($reserved['reason'] !== 'too_large') {
                $shortest = $shortest === null ? $reserved['retry_after'] : min($shortest, $reserved['retry_after']);
            }
        }

        return ['client' => null, 'wait' => $shortest, 'reason' => $shortest === null ? 'too_large' : 'quota'];
    }
}
