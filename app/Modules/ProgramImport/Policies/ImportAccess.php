<?php

namespace App\Modules\ProgramImport\Policies;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;

/** Who may use imports for an organization: its owner, and team members with admin/editor roles. Viewers read only. */
class ImportAccess
{
    private const WRITE = ['super_admin', 'super-admin', 'admin', 'editor'];

    private const READ = ['super_admin', 'super-admin', 'admin', 'editor', 'viewer'];

    public static function organizationId(User $user): ?int
    {
        return $user->organization_id ? (int) $user->organization_id : null;
    }

    public static function canWrite(User $user): bool
    {
        return self::has($user, self::WRITE);
    }

    public static function canRead(User $user): bool
    {
        return self::readDenial($user) === null;
    }

    /** Return an actionable explanation when this account cannot read import data. */
    public static function readDenial(User $user): ?array
    {
        if ((int) $user->user_type_id !== 4) {
            return [
                'code' => 'organization_account_required',
                'reason' => 'Program imports are available only to organization accounts. This account is not classified as an organization user.',
                //'required' => 'Sign in with an organization account (user_type_id 4).',
            ];
        }

        if (! $user->organization_id) {
            return [
                'code' => 'organization_required',
                'reason' => 'This account is not linked to an organization.',
                //'required' => 'Link this account to an organization, or sign in with an organization owner account.',
            ];
        }

        $org = Organization::find($user->organization_id);
        if ($org && (int) $org->owner_user_id === (int) $user->id) {
            return null;
        }

        $user->loadMissing('organizationRole.role');
        $role = $user->organizationRole?->role?->name;
        if (in_array($role, self::READ, true)) {
            return null;
        }

        return [
            'code' => 'organization_role_required',
            'reason' => $role
                ? "Your organization role '{$role}' is not allowed to view program imports."
                : 'You do not have an assigned role in this organization, and you are not its owner.',
            //'required' => 'Be the organization owner or have one of these roles: '.implode(', ', self::READ).'.',
        ];
    }

    private static function has(User $user, array $roles): bool
    {
        if ((int) $user->user_type_id !== 4 || ! $user->organization_id) {
            return false;
        }
        $org = Organization::find($user->organization_id);
        if ($org && (int) $org->owner_user_id === (int) $user->id) {
            return true;
        }
        $user->loadMissing('organizationRole.role');

        return in_array($user->organizationRole?->role?->name, $roles, true);
    }
}
