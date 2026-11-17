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
        return self::has($user, self::READ);
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
