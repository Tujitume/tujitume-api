<?php

namespace App\Service\Notification;

use App\Models\Auth\User;
use App\Models\Auth\UserSetting;
use App\Models\Organizations\Organization;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves how an applicant-facing email should look: the program owner's name,
 * logo and accent colour, falling back to the Tujitume defaults for anything not set.
 */
class EmailBrand
{
    public const DEFAULT_ACCENT = '#14532d';
    public const DEFAULT_NAME   = 'Tujitume';

    /** Colour stored on every UserSetting until the org picks its own — treated as "not set". */
    private const UNSET_ACCENT = '#16a34a';

    public static function defaults(): array
    {
        return [
            'custom'     => false,
            'name'       => self::DEFAULT_NAME,
            'accent'     => self::DEFAULT_ACCENT,
            'accent_dark' => '#0d3a1f',
            'on_accent'  => '#ffffff',
            'logo_data'  => null,
            'logo_mime'  => null,
        ];
    }

    /** Brand for a program (by id), or for the program an application belongs to. */
    public static function forData(array $data): array
    {
        try {
            $programId = $data['program_id'] ?? null;
            if (!$programId && !empty($data['application_id'])) {
                $programId = ProgramApplication::whereKey($data['application_id'])->value('program_id');
            }
            $program = $programId ? Program::with('owner.organization')->find($programId) : null;

            return $program ? self::forProgram($program) : self::defaults();
        } catch (\Throwable $e) {
            Log::warning('EmailBrand: falling back to default brand', ['error' => $e->getMessage()]);
            return self::defaults();
        }
    }

    public static function forProgram(Program $program): array
    {
        return self::forOwner($program->owner);
    }

    /**
     * Brand for an account email (OTP / device codes, invitations): the user's own
     * organisation, when they belong to one. Individuals get Tujitume's look.
     */
    public static function forUser(?User $user): array
    {
        try {
            if (!$user || !$user->organization_id) return self::defaults();

            return self::forOwner(User::with('organization')->find($user->organizationOwnerId()));
        } catch (\Throwable $e) {
            Log::warning('EmailBrand: falling back to default brand', ['error' => $e->getMessage()]);
            return self::defaults();
        }
    }

    public static function forEmail(?string $email): array
    {
        return $email ? self::forUser(User::where('email', $email)->first()) : self::defaults();
    }

    /** Brand for an organisation, through the account that owns it (name, logo, accent). */
    public static function forOrganization(?Organization $organization): array
    {
        return $organization?->owner_user_id
            ? self::forOwner(User::with('organization')->find($organization->owner_user_id))
            : self::defaults();
    }

    /** The owner account's organisation name plus its saved logo and accent colour. */
    private static function forOwner(?User $owner): array
    {
        $brand = self::defaults();
        if (!$owner) return $brand;

        $org  = $owner->organization;
        $name = $org?->display_name ?: $org?->name ?: $owner->display_name;
        if ($name) {
            $brand['custom'] = true;
            $brand['name']   = $name;
        }

        $settings = UserSetting::where('user_id', $owner->id)->first();

        $accent = $settings?->accent_color;
        if (self::isHex($accent) && strtolower($accent) !== self::UNSET_ACCENT) {
            $brand['accent']      = $accent;
            $brand['accent_dark'] = self::darken($accent);
            $brand['on_accent'] = self::readableOn($accent);
        }

        if ($settings?->logo && ($logo = self::loadLogo($settings->logo))) {
            $brand['logo_data'] = $logo['data'];
            $brand['logo_mime'] = $logo['mime'];
        }

        return $brand;
    }

    /** Logos already fetched in this request, so a bulk send downloads each one once. */
    private static array $logoCache = [];

    private static function loadLogo(string $logo): ?array
    {
        // array_key_exists, not ??=, so a logo that failed to load is not retried for every recipient
        if (!array_key_exists($logo, self::$logoCache)) self::$logoCache[$logo] = self::fetchLogo($logo);

        return self::$logoCache[$logo];
    }

    private static function fetchLogo(string $logo): ?array
    {
        try {
            if (str_starts_with($logo, 'data:image/')) {
                [$meta, $payload] = explode(',', $logo, 2);
                $data = base64_decode($payload, true);
                $mime = str_replace(['data:', ';base64'], '', $meta);
                return $data ? ['data' => $data, 'mime' => $mime] : null;
            }

            $response = Http::timeout(5)->get($logo);
            if (!$response->successful()) return null;

            $mime = strtok($response->header('Content-Type', 'image/png'), ';');
            // SVG is not rendered by most mail clients
            if (!str_starts_with($mime, 'image/') || str_contains($mime, 'svg')) return null;

            return ['data' => $response->body(), 'mime' => $mime];
        } catch (\Throwable $e) {
            Log::warning('EmailBrand: could not load logo', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private static function isHex(?string $color): bool
    {
        return is_string($color) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color) === 1;
    }

    /** The colour at ~70% brightness — the far end of the header gradient. */
    private static function darken(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);

        return sprintf('#%02x%02x%02x', (int) ($r * 0.7), (int) ($g * 0.7), (int) ($b * 0.7));
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];

        return array_map('hexdec', str_split($hex, 2));
    }

    /** Black or white, whichever reads better on the given background. */
    private static function readableOn(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.6 ? '#111827' : '#ffffff';
    }
}
