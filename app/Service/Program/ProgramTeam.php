<?php

namespace App\Service\Program;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Programs\Program;
use Illuminate\Support\Collection;

/** Everyone on a program's owner side who should hear about it: the creator, the organization's owner and its program team. */
class ProgramTeam
{
    private const ROLES = ['super_admin', 'super-admin', 'admin', 'editor'];

    /** @return Collection<int, User> */
    public static function members(?Program $program): Collection
    {
        if (! $program) return collect();
        $people = collect([$program->owner ?? User::find($program->user_id)]);
        $orgId = $program->organizationId();
        if ($orgId) {
            $people->push(User::find(Organization::whereKey($orgId)->value('owner_user_id')));
            User::where('organization_id', $orgId)->where('user_type_id', 4)->with('organizationRole.role')->get()
                ->filter(fn (User $u) => in_array($u->organizationRole?->role?->name, self::ROLES, true))
                ->each(fn (User $u) => $people->push($u));
        }

        return $people->filter()->unique('id')->values();
    }
}
