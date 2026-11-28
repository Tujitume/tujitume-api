<?php

namespace App\Service\Subscription;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Service\Organization\OrganizationPermission;

/**
 * Whose subscription a person sees and pays for.
 *
 * Individuals (entrepreneurs, investors, service providers) have their own. People who work inside an organisation
 * share the organisation's subscription: it is kept on the organisation's owner (business_subscriptions.investor_id),
 * so every team member gets the same plan. Only the owner and super admins buy, renew or cancel it; everyone else
 * sees it read-only.
 */
class SubscriptionHolder
{
    /** The user id the subscription is stored under. */
    public static function idFor(User $user): int
    {
        $organization = self::organizationOf($user);

        return (int) ($organization?->owner_user_id ?: $user->id);
    }

    /** Is this subscription the organisation's (shared with the team)? */
    public static function isOrganisation(User $user): bool
    {
        return (bool) self::organizationOf($user)?->owner_user_id;
    }

    /** May this person buy, renew or cancel it? */
    public static function canManage(User $user): bool
    {
        return ! self::isOrganisation($user) || OrganizationPermission::isAdmin($user);
    }

    private static function organizationOf(User $user): ?Organization
    {
        // Program organisations' people (type 4) work inside their organisation; others are on their own
        if ((int) $user->user_type_id !== 4 || ! $user->organization_id) return null;

        return Organization::select('id', 'owner_user_id', 'name')->find($user->organization_id);
    }
}
