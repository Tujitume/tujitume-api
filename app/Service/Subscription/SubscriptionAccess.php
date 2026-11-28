<?php

namespace App\Service\Subscription;

use App\Models\Auth\User;
use App\Models\Business\BusinessSubscriptions;
use App\Models\Organizations\Organization;

/**
 * Program organisations work only with an active plan, the same way they work only once KYC is verified: until both,
 * creating, importing and publishing programs is blocked (the app says which step is missing). They may pay before or
 * after KYC. The plan is the organisation's (kept on its owner, SubscriptionHolder), so the whole team shares it.
 * A 7-day demo account is exempt (it has its own trial), and other account types are not asked for a plan here.
 */
class SubscriptionAccess
{
    public const CODE = 'subscription_required';

    public const MESSAGE = 'Choose a plan for your organisation to do this. Go to Subscription to pick one.';

    /** Is this person's work held back until their organisation has a plan? */
    public static function required(?User $user): bool
    {
        if (! $user || (int) $user->user_type_id !== 4) return false;
        $trial = $user->relationLoaded('organization')
            ? (bool) $user->organization?->is_trial
            : ($user->organization_id && Organization::whereKey($user->organization_id)->where('is_trial', true)->exists());
        if ($trial) return false;

        return true;
    }

    /** The organisation's (or person's) plan is running: not expired, not switched off */
    public static function active(User $user): bool
    {
        return BusinessSubscriptions::where('investor_id', SubscriptionHolder::idFor($user))
            ->where('active', 1)
            ->where('expire_date', '>', now())
            ->exists();
    }

    public static function blocked(?User $user): bool
    {
        return self::required($user) && ! self::active($user);
    }
}
