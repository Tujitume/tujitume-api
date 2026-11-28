<?php

namespace App\Service\LiprMpesa;

/**
 * ISSUE-48: LIPR callbacks are public URLs, and LIPR does not sign what it posts. So every result / timeout URL the app
 * hands LIPR carries a signature of its own path (`?sig=`), made with a secret only this backend knows
 * (services.lipr.callback_secret, default: the app key). VerifyLiprCallback refuses any callback without a valid one, so
 * nobody can post a fake "Successful" transfer to credit a balance or mark a payment paid.
 */
class LiprCallback
{
    /** The full callback URL for a path under the API, e.g. url('/lipr-callback/service-milestone'). */
    public static function url(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return rtrim((string) config('app.api_url'), '/').$path.'?sig='.self::signature($path);
    }

    public static function signature(string $path): string
    {
        // Signed on the path after /api so the same URL verifies whatever host or prefix is in front
        $path = '/'.ltrim(preg_replace('#^/?api(?=/)#', '', $path), '/');

        return hash_hmac('sha256', $path, self::secret());
    }

    public static function valid(string $path, ?string $sig): bool
    {
        return is_string($sig) && $sig !== '' && hash_equals(self::signature($path), $sig);
    }

    private static function secret(): string
    {
        return (string) (config('services.lipr.callback_secret') ?: config('app.key'));
    }
}
