<?php

namespace App\Service\Organization;

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * What a member of a company (a program or capital organization) may do, decided once for the whole API.
 *
 *   owner, super_admin  everything
 *   editor              view, create, edit and review; never delete, manage the team or the organization's settings
 *   viewer              view only
 *   internal_reviewer   view and review (score and review what they are assigned)
 *   none                a deactivated (revoked) or declined member: nothing
 *
 * People who are not a team member of a company (applicants, Business Coaches, someone with their own account) have no
 * company role here (null) and are governed only by each endpoint's own checks.
 */
class OrganizationPermission
{
    public const OWNER = 'owner';
    public const NONE = 'none';

    public const DENIED = "Your role doesn't allow this action.";

    /** What each role may do */
    private const ABILITIES = [
        'owner' => ['*'],
        'super_admin' => ['*'],
        'editor' => ['view', 'create', 'edit', 'review'],
        'viewer' => ['view'],
        'internal_reviewer' => ['view', 'review'],
        'none' => [],
    ];

    /** Membership states that end access (Deactivate on Team members sets revoked) */
    private const ENDED = ['revoked', 'declined'];

    /** Endpoints that change who is on the team or their role (owner and super admins only) */
    private const TEAM_ROUTES = ['update-user', 'delete/role-user', 'accept-invitation'];

    /** Endpoints that change the organization's own profile and settings (owner and super admins only) */
    private const ORGANIZATION_ROUTES = ['update-profile'];

    /**
     * Endpoints about the person's own account or choices, or work the owner assigned to them by name (the controller
     * checks they are the assignee): open to every active role
     */
    private const PERSONAL_ROUTES = [
        'delete-user',
        'ai-privacy-ack',
        'monitoring/checkpoints/{checkpoint}/submit',
        'monitoring/site-visits/{visit}/accept',
        'monitoring/site-visits/{visit}/propose-fee',
        'monitoring/site-visits/{visit}/decline',
        'monitoring/site-visits/{visit}/submit',
    ];

    /** DELETE endpoints that are edits, not deletions (Editors may do them): unassigning a Business Coach, removing round questions */
    private const EDITING_DELETES = [
        'rounds/{round}/applications/{application}/assignment',
        'questions/{question}',
        'rounds/{round}/questions',
    ];

    /** GET endpoints that change data (they count as edits) */
    private const CHANGING_GETS = ['visibility', 'store-watchlist', 'accept', 'reject', 'accept-invitation', 'delete-capital'];

    /**
     * Review work (scoring, starting a review, accepting a review request, AI findings, delivering a review): allowed to
     * internal reviewers as well as editors.
     */
    private const REVIEW_ROUTES = [
        'applications/{application}/scores',
        'rounds/{round}/reviewers/accept',
        'rounds/{round}/reviewers/decline',
        'rounds/{round}/applications/{application}/start-review',
        'applications/{application}/ai-review/findings',
        'ai-findings/{finding}',
        'reviewer-orders/{order}/deliver',
    ];

    /** The person's company role: owner, super_admin, editor, viewer, internal_reviewer, none, or null (not a team member) */
    public static function roleOf(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        // Capital organizations (older capital accounts were saved as type 3) keep their team on capital_profiles
        if (in_array((int) $user->user_type_id, [3, 5], true) && $user->capital_profile()->exists()) {
            return self::capitalRoleOf($user);
        }

        if (! $user->organization_id) {
            return null;
        }

        $ownerId = Organization::whereKey($user->organization_id)->value('owner_user_id');
        if ($ownerId !== null && (int) $ownerId === (int) $user->id) {
            return self::OWNER;
        }

        $membership = OrganizationUserRole::query()
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->with('role:id,name')
            ->first();

        if (! $membership) {
            return null;
        }

        if (in_array($membership->status, self::ENDED, true)) {
            return self::NONE;
        }

        return self::normalize($membership->role?->name);
    }

    /** Capital organizations keep their team on capital_profiles: no role is the owner, a role is a team member */
    private static function capitalRoleOf(User $user): ?string
    {
        $profile = $user->capital_profile()->with('role:id,name')->first();
        if (! $profile) {
            return null;
        }
        if ($profile->role_id === null) {
            return self::OWNER;
        }
        if (! $profile->active) {
            return self::NONE;
        }

        return self::normalize($profile->role?->name);
    }

    private static function normalize(?string $role): string
    {
        $role = str_replace('-', '_', strtolower(trim((string) $role)));
        if ($role === 'admin' || $role === 'superadmin') {
            $role = 'super_admin';
        }

        // An unknown role is given the least access, never more
        return array_key_exists($role, self::ABILITIES) ? $role : 'viewer';
    }

    /** May this person do this (view, create, edit, delete, review, manage_team, manage_organization)? */
    public static function can(?User $user, string $ability): bool
    {
        $role = self::roleOf($user);
        if ($role === null) {
            return true;
        }

        return self::roleCan($role, $ability);
    }

    public static function roleCan(string $role, string $ability): bool
    {
        $abilities = self::ABILITIES[$role] ?? [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    /** True for the owner and active super admins: the only people who manage the team and the organization */
    public static function isAdmin(?User $user): bool
    {
        return in_array(self::roleOf($user), [self::OWNER, 'super_admin'], true);
    }

    /** What this request does, as an ability */
    public static function abilityFor(Request $request, ?string $prefix = null): string
    {
        $uri = (string) Route::current()?->uri();
        // The path inside the group ("programs/applications/{application}/scores" -> "applications/{application}/scores")
        $path = preg_replace('#^(api/)?(v1/)?'.preg_quote((string) $prefix, '#').'/#', '', $uri);
        $last = self::lastSegment($uri);
        $method = strtoupper($request->method());
        $reading = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);

        if (in_array($path, self::PERSONAL_ROUTES, true)) {
            return 'personal';
        }
        if (in_array($path, self::TEAM_ROUTES, true)) {
            return 'manage_team';
        }
        if (in_array($path, self::ORGANIZATION_ROUTES, true)) {
            return 'manage_organization';
        }
        if (in_array($path, self::EDITING_DELETES, true)) {
            return 'edit';
        }
        if ($method === 'DELETE' || str_starts_with((string) $last, 'delete')) {
            return 'delete';
        }
        if (in_array($path, self::REVIEW_ROUTES, true)) {
            return 'review';
        }
        if ($reading && ! in_array($last, self::CHANGING_GETS, true)) {
            return 'view';
        }

        return $method === 'POST' && preg_match('/(^|-)(create|store)(-|$)/', (string) $last) ? 'create' : 'edit';
    }

    /** The refusal every endpoint sends, so the app shows the same "your role doesn't allow this" message */
    public static function denied(?string $message = null): JsonResponse
    {
        $message = $message ?: self::DENIED;

        return response()->json(['success' => false, 'code' => 'forbidden', 'message' => $message, 'error' => $message], 403);
    }

    /** null when the request may go ahead, else the 403 to send */
    public static function check(Request $request, ?string $prefix = null): ?JsonResponse
    {
        $role = self::roleOf($request->user());
        if ($role === null) {
            return null;
        }

        $ability = self::abilityFor($request, $prefix);
        if ($ability === 'personal' && $role !== self::NONE) {
            return null;
        }

        if ($role === self::NONE) {
            return self::denied('Your access to this organization has been deactivated.');
        }

        return self::roleCan($role, $ability) ? null : self::denied();
    }

    private static function lastSegment(string $uri): ?string
    {
        $segments = array_filter(explode('/', $uri), fn ($seg) => $seg !== '' && ! str_starts_with($seg, '{'));

        return end($segments) ?: null;
    }
}
