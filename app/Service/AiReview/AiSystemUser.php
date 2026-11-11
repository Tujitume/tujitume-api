<?php

namespace App\Service\AiReview;

use App\Models\Auth\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * "Tujitume AI Reviewer": owns AI-only score rows (application_scores.reviewer_id is a required user).
 * It has no user type, so it appears in no reviewer or provider list, and it can never receive email or notifications.
 */
class AiSystemUser
{
    public static function email(): string
    {
        return (string) config('ai_review.system_user_email');
    }

    public static function get(): User
    {
        return User::where('email', self::email())->first() ?? tap(new User(), function (User $u) {
            $u->forceFill([
                'first_name' => 'Tujitume', 'last_name' => 'AI Reviewer', 'display_name' => 'Tujitume AI Reviewer',
                'email' => self::email(), 'password' => Hash::make(Str::random(64)), 'email_verified_at' => now(),
            ])->save();
        });
    }

    public static function is(?User $user): bool
    {
        return $user && strcasecmp((string) $user->email, self::email()) === 0;
    }
}
