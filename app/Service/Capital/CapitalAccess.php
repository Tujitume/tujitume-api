<?php

namespace App\Service\Capital;

use App\Models\Auth\User;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\CapitalProfile;
use App\Models\Capital\StartupPitches;

/**
 * Who a capital provider is (user type 5) and whose offers they act on.
 *
 * A capital organization is its owner (a type-5 account whose capital_profile has no role) plus team members (type-5
 * accounts whose capital_profile has role_id and capital_owner_id = the owner). Offers, applications, the watchlist and
 * analytics all belong to the owner; a team member works on the owner's.
 */
class CapitalAccess
{
    public const TYPE = 5;

    public static function isCapital(?User $user): bool
    {
        return $user !== null && (int) $user->user_type_id === self::TYPE;
    }

    /** The capital owner this person acts for (themselves for the owner), or null when they are not a capital provider */
    public static function ownerId(?User $user): ?int
    {
        if (! self::isCapital($user)) {
            return null;
        }
        $profile = $user->relationLoaded('capital_profile') ? $user->capital_profile : $user->capital_profile()->first();

        return $profile && $profile->role_id !== null && $profile->capital_owner_id ? (int) $profile->capital_owner_id : (int) $user->id;
    }

    public static function ownsOffer(?User $user, ?CapitalOffer $offer): bool
    {
        $owner = self::ownerId($user);

        return $owner !== null && $offer !== null && (int) $offer->user_id === $owner;
    }

    /** The capital side (owner or team) of this application */
    public static function isProviderOf(?User $user, ?StartupPitches $pitch): bool
    {
        $owner = self::ownerId($user);

        return $owner !== null && $pitch !== null && (int) $pitch->capital_owner_id === $owner;
    }

    /** The applicant side of this application: the entrepreneur, or their organization owner */
    public static function isApplicantOf(?User $user, ?StartupPitches $pitch): bool
    {
        if (! $user || ! $pitch) {
            return false;
        }

        return in_array((int) $pitch->user_id, array_unique([(int) $user->id, (int) $user->organizationOwnerId()]), true);
    }

    /**
     * Who hears about a provider-side step: the owner plus active team members who act (super admins and editors), the
     * same team scope as the program side. Viewers only read.
     *
     * @return int[]
     */
    public static function providerRecipients(int $ownerId): array
    {
        $team = CapitalProfile::query()
            ->where('capital_owner_id', $ownerId)
            ->whereNotNull('role_id')
            ->where('active', 1)
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['super_admin', 'admin', 'editor']))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([$ownerId, ...$team]));
    }
}
