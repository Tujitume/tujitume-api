<?php

namespace App\Service\Organization;

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\User;
use App\Models\Organizations\Organization;

/**
 * Can this email be invited into this organization? An account belongs to one organization, so an email that
 * is already in use gets a message that says exactly why, instead of a bare "email has already been taken".
 */
class InviteEmailCheck
{
    /** The reason the email cannot be invited into $organizationId, or null when it is free to use. */
    public static function conflict(string $email, int $organizationId): ?string
    {
        $existing = User::with('user_type')
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->first();

        if (! $existing) {
            return null;
        }

        // Already part of this organization
        if ((int) $existing->organization_id === $organizationId) {
            $status = OrganizationUserRole::where('organization_id', $organizationId)
                ->where('user_id', $existing->id)
                ->value('status');

            return match ($status) {
                'revoked' => "{$email} is already in your organisation but their access is deactivated. Reactivate them from Team Members instead of inviting them again.",
                'pending' => "{$email} has already been invited to your organisation and has not accepted yet. Reopen or resend their invitation from Team Members.",
                default => "{$email} is already part of your organisation.",
            };
        }

        // Part of a different organization
        if ($existing->organization_id) {
            $other = Organization::find($existing->organization_id);
            $name = $other?->display_name ?: $other?->name ?: 'another organisation';

            return "{$email} is already allocated to another organisation ({$name}), and an account can belong to only one organisation. "
                . 'To work with several organisations, they can create a service provider account with a different email and be added with that.';
        }

        // A normal account (entrepreneur, investor, service provider...) that is not in any organization
        $kind = $existing->user_type?->name ? strtolower($existing->user_type->name) : 'another type';

        return "{$email} already has a Tujitume account ({$kind}). Use a different email to invite them to your organisation.";
    }
}
