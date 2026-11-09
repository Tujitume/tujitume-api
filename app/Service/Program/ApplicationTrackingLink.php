<?php

namespace App\Service\Program;

use App\Models\Programs\ProgramApplication;

/**
 * The secure "Track Your Application" link. It is sent only to the application's contact email, so opening
 * it proves access to that email. Stateless: the token is an HMAC of the application UID and an expiry.
 */
class ApplicationTrackingLink
{
    public const DAYS_VALID = 60;

    public static function url(ProgramApplication $application): string
    {
        $expires = now()->addDays(self::DAYS_VALID)->timestamp;
        $base = rtrim(config('app.frontend_url', 'https://tujitume.com'), '/');

        return $base . '/track-application/' . $application->uid . '?' . http_build_query([
            'e' => $expires,
            't' => self::sign($application->uid, $expires),
        ]);
    }

    public static function valid(string $uid, mixed $expires, mixed $token): bool
    {
        if (!is_string($token) || !ctype_digit((string) $expires) || (int) $expires < now()->timestamp) {
            return false;
        }

        return hash_equals(self::sign($uid, (int) $expires), $token);
    }

    private static function sign(string $uid, int $expires): string
    {
        return hash_hmac('sha256', $uid . '|' . $expires, (string) config('app.key'));
    }
}
