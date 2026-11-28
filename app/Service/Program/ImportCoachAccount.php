<?php

namespace App\Service\Program;

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Organizations\OrganizationProvider;

/**
 * Who a Business Coaches row of a program import is, by email, the same way Round Config allows them:
 *  - a team member: the organization's owner, or someone on its team (active, or invited and not yet accepted), or
 *    someone new to Tujitume (the import then adds them to the team, waiting to accept, and invites them later);
 *  - a Business Coach (from outside): a Tujitume service provider (a third-party coach, who is paid; added to the
 *    organization's provider list when not on it yet), an outside coach already in this organization, or someone
 *    new to Tujitume (the import then makes their account quietly; they are invited only when the person chooses).
 * The import's check (step 4) and the import itself (step 5) both ask here, so they always agree.
 */
class ImportCoachAccount
{
    /**
     * Who each email is, for the review page (said while the person fixes, not only at Check): the kind of
     * Business Coaches row it can be. Only emails of the import's own rows are asked about.
     * @param array<int, array{email: string, kind: string}> $people
     * @return array<string, array{as: ?string, problem: ?string, listed: bool}> keyed "kind:email"
     */
    public static function describe(array $people, int $organizationId): array
    {
        $out = [];
        foreach (array_slice($people, 0, 500) as $p) {
            $email = mb_strtolower(trim((string) ($p['email'] ?? '')));
            $kind = ($p['kind'] ?? '') === 'team' ? 'team' : 'coach';
            if ($email === '' || isset($out["{$kind}:{$email}"])) continue;
            $found = self::find($email, $kind, $organizationId);
            $out["{$kind}:{$email}"] = ['as' => $found['as'], 'problem' => $found['problem'], 'listed' => $found['listed']];
        }

        return $out;
    }

    public const COACH_TYPE = 6; // external reviewer (Business Coach)
    public const PROVIDER_TYPE = 3; // service provider (a third-party Business Coach)

    /**
     * @return array{user: ?User, problem: ?string, as: string, listed: bool}
     *   as: team | new_team | provider | coach | new; listed: a provider already on the organization's list
     *   problem: coachNotInTeam (the email is another kind of account) | coachEmailTaken
     */
    public static function find(string $email, string $kind, int $organizationId): array
    {
        $email = mb_strtolower(trim($email));
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($kind === 'team') {
            if (! $user) return ['user' => null, 'problem' => null, 'as' => 'new_team', 'listed' => false];
            $owner = (int) Organization::whereKey($organizationId)->value('owner_user_id');
            $onTeam = (int) $user->id === $owner || OrganizationUserRole::where('organization_id', $organizationId)
                ->where('user_id', $user->id)->whereIn('status', ['active', 'pending'])->exists();

            return ['user' => $onTeam ? $user : null, 'problem' => $onTeam ? null : 'coachNotInTeam', 'as' => 'team', 'listed' => false];
        }

        if (! $user) return ['user' => null, 'problem' => null, 'as' => 'new', 'listed' => false];
        if ((int) $user->user_type_id === self::PROVIDER_TYPE) {
            return ['user' => $user, 'problem' => null, 'as' => 'provider', 'listed' => OrganizationProvider::has($organizationId, (int) $user->id)];
        }
        $ours = (int) $user->user_type_id === self::COACH_TYPE && (int) $user->organization_id === $organizationId;

        return ['user' => $ours ? $user : null, 'problem' => $ours ? null : 'coachEmailTaken', 'as' => 'coach', 'listed' => false];
    }
}
