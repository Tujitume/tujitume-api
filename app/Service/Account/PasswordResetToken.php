<?php

namespace App\Service\Account;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Proof that someone has just entered the code emailed to an address. Checking the code (verifyEmailOtp) issues one;
 * setting a new password (resetPassword) needs it and uses it up. Without it, resetPassword would let anyone set any
 * account's password from its email address alone.
 */
class PasswordResetToken
{
    public const MINUTES = 15;

    public static function issue(string $email): string
    {
        $token = Str::random(64);
        Cache::put(self::key($token), self::normalise($email), now()->addMinutes(self::MINUTES));

        return $token;
    }

    /** True once, for the address the token was issued to. */
    public static function consume(string $email, ?string $token): bool
    {
        if (! is_string($token) || strlen($token) !== 64) return false;

        $issuedFor = Cache::pull(self::key($token));

        return is_string($issuedFor) && hash_equals($issuedFor, self::normalise($email));
    }

    private static function key(string $token): string
    {
        return 'password_reset:'.hash('sha256', $token);
    }

    private static function normalise(string $email): string
    {
        return strtolower(trim($email));
    }
}
