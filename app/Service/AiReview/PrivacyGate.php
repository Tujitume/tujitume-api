<?php

namespace App\Service\AiReview;

use App\Models\Organizations\Organization;
use App\Models\Programs\Program;

/**
 * AI review may use a provider only if applicant data may go there: either the provider is configured as allowed and
 * does not train on data, or the organisation has acknowledged that provider's terms.
 */
class PrivacyGate
{
    public function providerConfig(string $provider): ?array
    {
        return config("ai_review.providers.{$provider}");
    }

    public function organizationFor(Program $program): ?Organization
    {
        $program->loadMissing('owner');
        $orgId = $program->owner?->organization_id;

        return $orgId ? Organization::find($orgId) : null;
    }

    public function satisfied(string $provider, ?Organization $organization): bool
    {
        $cfg = $this->providerConfig($provider);
        if (! $cfg) return false;

        if (($cfg['allow_applicant_data'] ?? false) && ($cfg['trains_on_data'] ?? 'unknown') === false) {
            return true;
        }

        if (! $organization || ! $organization->ai_data_ack_at) return false;
        if ($organization->ai_data_ack_provider === $provider) return true;

        // Accepted for 'amari' counts for 'groq' (and the other way) when both talk to the same service: it is the same company receiving the text
        $accepted = $this->providerConfig((string) $organization->ai_data_ack_provider);
        $host = fn (?array $c) => strtolower((string) parse_url((string) ($c['base_url'] ?? ''), PHP_URL_HOST));

        return $accepted && $host($accepted) !== '' && $host($accepted) === $host($cfg);
    }

    /** Providers usable for this program right now: configured, credentialed and passing the gate. */
    public function allowedProviders(Program $program): array
    {
        $org = $this->organizationFor($program);
        $names = array_values(array_unique(array_filter([config('ai_review.default_provider'), ...config('ai_review.fallbacks', [])])));

        return array_values(array_filter($names, fn ($name) => $this->configured($name) && $this->satisfied($name, $org)));
    }

    /** Credentials present; no live call. */
    public function configured(string $provider): bool
    {
        $cfg = $this->providerConfig($provider);

        return $cfg && ! empty($cfg['base_url']) && ! empty($cfg['model']) && ! empty($cfg['api_key']);
    }
}
