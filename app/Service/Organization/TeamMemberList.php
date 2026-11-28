<?php

namespace App\Service\Organization;

use App\Models\Auth\OrganizationUserRole;
use App\Models\Organizations\Organization;
use App\Support\ApiContract;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Team page's member list: a page of the organization's memberships with search, filters, sort and export on the
 * database, the option counts for the filter popover, and the totals for the summary cards over the WHOLE team (the
 * cards do not change with the search).
 *
 * What a row shows is worked out the same way the page always did:
 *   status   the organization owner is always active; a revoked membership is "deactivated"; an active one "active";
 *            anything else (an invitation not accepted yet) "pending"
 *   role     super_admin / super-admin -> "super-admin", editor, anything else "viewer"
 *   joined   when they accepted, else when the membership was made, else when they were invited
 */
class TeamMemberList
{
    // internal-reviewer = an internal Business Coach (view + review what they are assigned)
    public const ROLE_KEYS = ['super-admin', 'editor', 'viewer', 'internal-reviewer'];
    public const STATUS_KEYS = ['active', 'pending', 'deactivated'];

    public function __construct(private Organization $organization)
    {
    }

    /** True when the request asks for the paged / searchable form (the old full list stays for everything else). */
    public static function wanted(Request $request): bool
    {
        return ApiContract::wantsPagination($request) || $request->boolean('export')
            || $request->filled('search') || $request->filled('filters') || $request->filled('sort');
    }

    /** Each member as the old endpoint sent it; the list form adds the worked-out keys the table reads. */
    public static function payload(OrganizationUserRole $membership, bool $withKeys = false, ?int $ownerId = null): array
    {
        $row = [
            'id' => $membership->user->id,
            'first_name' => $membership->user->first_name,
            'last_name' => $membership->user->last_name,
            'display_name' => $membership->user->display_name,
            'email' => $membership->user->email,
            'phone' => $membership->user->phone,
            'image' => $membership->user->image,
            'user_type_id' => $membership->user->user_type_id,
            'organization_id' => $membership->organization_id,
            'membership' => [
                'id' => $membership->id,
                'status' => $membership->status,
                'invited_at' => $membership->invited_at?->toISOString(),
                'accepted_at' => $membership->accepted_at?->toISOString(),
                'revoked_at' => $membership->revoked_at?->toISOString(),
                'invitation_expires_at' => $membership->invitation_expires_at?->toISOString(),
                'created_at' => $membership->created_at?->toISOString(),
                'updated_at' => $membership->updated_at?->toISOString(),
            ],
            'role' => [
                'id' => $membership->role->id,
                'name' => $membership->role->name,
                'access_types' => $membership->role->access_types,
            ],
        ];

        if ($withKeys) {
            $owner = $ownerId !== null && (int) $membership->user_id === $ownerId;
            $joined = $membership->accepted_at ?? $membership->created_at ?? $membership->invited_at;
            $row += [
                'owner' => $owner,
                'status_key' => $owner ? 'active' : match ($membership->status) {
                    'revoked' => 'deactivated',
                    'active' => 'active',
                    default => 'pending',
                },
                'role_key' => self::roleKey($membership->role->name),
                'joined_at' => $joined?->toISOString(),
            ];
        }

        return $row;
    }

    public static function roleKey(?string $name): string
    {
        return match ($name) {
            'super_admin', 'super-admin' => 'super-admin',
            'editor' => 'editor',
            'internal_reviewer' => 'internal-reviewer',
            default => 'viewer',
        };
    }

    public function respond(Request $request): JsonResponse
    {
        $list = $this->list($request);
        [$rows, $page] = $list->fetch();

        $ownerId = (int) $this->organization->owner_user_id;
        $items = $rows->map(fn (OrganizationUserRole $m) => self::payload($m, true, $ownerId))->values();

        $data = [
            'items' => $items,
            'filter_schema' => $list->filterSchema(),
            'facets' => [
                'status' => $this->counts($list->query('status', sorted: false), $this->statusSql(), self::STATUS_KEYS),
                'role' => $this->counts($list->query('role', sorted: false), self::roleSql(), self::ROLE_KEYS),
            ],
            'stats' => $this->stats(),
            'export_truncated' => false,
        ];
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }

        return response()->json([
            'organization_id' => $this->organization->id,
            'users' => $items,
            'data' => $data,
        ], 200);
    }

    /** Every membership of the organization, with its user and role (joined so the list can sort by name and role). */
    private function base(): Builder
    {
        $joined = DB::table('organization_user_roles')
            ->selectRaw('id as joined_id, COALESCE(accepted_at, created_at, invited_at) as joined_at');

        return OrganizationUserRole::query()
            ->select('organization_user_roles.*')
            ->join('users as member', 'member.id', '=', 'organization_user_roles.user_id')
            ->leftJoin('roles as member_role', 'member_role.id', '=', 'organization_user_roles.role_id')
            ->joinSub($joined, 'joined', 'joined.joined_id', '=', 'organization_user_roles.id')
            ->where('organization_user_roles.organization_id', $this->organization->id)
            ->with([
                'role:id,name,access_types',
                'user:id,first_name,last_name,display_name,email,phone,image,user_type_id,organization_id',
            ]);
    }

    private function list(Request $request): ListQuery
    {
        return ListQuery::for($this->base(), $request)
            ->search(['user.first_name', 'user.last_name', 'user.email', 'user.display_name'])
            ->filter('id', 'user_id')
            // Described filters: the Team page's panel, chips and URL state come from `filter_schema`
            ->options('role', fn (Builder $q, array $values) => $this->whereIn($q, self::roleSql(), $values, self::ROLE_KEYS),
                label: 'app:role', values: self::ROLE_KEYS, optionLabels: ['super-admin' => 'app:superAdmin', 'editor' => 'app:editor', 'viewer' => 'app:viewer', 'internal-reviewer' => 'app:roleBusinessCoach'],
                countBy: DB::raw(self::roleSql()))
            ->options('status', fn (Builder $q, array $values) => $this->whereIn($q, $this->statusSql(), $values, self::STATUS_KEYS),
                label: 'app:status', values: self::STATUS_KEYS, optionLabels: ['active' => 'app:active', 'pending' => 'app:pending2', 'deactivated' => 'app:deactivated'],
                countBy: DB::raw($this->statusSql()))
            ->dateRange('joined', 'joined.joined_at', label: 'app:listFilterJoined', fallback: 'Joined')
            ->dateRange('invited', 'invited_at', label: 'app:invited')
            // An invitation nobody accepted before it ran out (the owner is never one)
            ->boolean('expired', fn (Builder $q, bool $yes) => $this->whereExpired($q, $yes), label: 'app:listFilterInviteExpired', fallback: 'Invitation expired')
            ->sortable(['name' => 'member.first_name', 'role' => 'member_role.name', 'joined' => 'joined.joined_at'], default: 'joined');
    }

    private function whereExpired(Builder $q, bool $yes): void
    {
        $expired = fn (Builder $w) => $w->whereRaw("({$this->statusSql()}) = 'pending'")
            ->whereNotNull('organization_user_roles.invitation_expires_at')
            ->where('organization_user_roles.invitation_expires_at', '<', now());
        $yes ? $expired($q) : $q->whereNot(fn (Builder $n) => $expired($n));
    }

    /** The member's status as SQL (the owner id is an integer from the database, never request input). */
    private function statusSql(): string
    {
        $owner = (int) $this->organization->owner_user_id;

        return "CASE WHEN organization_user_roles.user_id = {$owner} THEN 'active'"
            ." WHEN organization_user_roles.status = 'revoked' THEN 'deactivated'"
            ." WHEN organization_user_roles.status = 'active' THEN 'active' ELSE 'pending' END";
    }

    private static function roleSql(): string
    {
        return "CASE WHEN member_role.name IN ('super_admin', 'super-admin') THEN 'super-admin'"
            ." WHEN member_role.name = 'editor' THEN 'editor'"
            ." WHEN member_role.name = 'internal_reviewer' THEN 'internal-reviewer' ELSE 'viewer' END";
    }

    private function whereIn(Builder $q, string $sql, array $values, array $known): void
    {
        $values = array_values(array_intersect($known, array_map('strval', $values)));
        if (! $values) {
            $q->whereRaw('1 = 0');

            return;
        }
        $q->whereRaw("({$sql}) in (".implode(', ', array_fill(0, count($values), '?')).')', $values);
    }

    /** [{value, count}] of a worked-out key, in its usual order, leaving out the empty ones. */
    private function counts(Builder $q, string $sql, array $order): array
    {
        $counts = $q->reorder()->toBase()
            ->select(DB::raw("({$sql}) as value"), DB::raw('count(*) as count'))
            ->groupBy(DB::raw("({$sql})"))
            ->pluck('count', 'value');

        return collect($order)
            ->filter(fn ($key) => (int) ($counts[$key] ?? 0) > 0)
            ->map(fn ($key) => ['value' => $key, 'count' => (int) $counts[$key]])
            ->values()
            ->all();
    }

    /** Totals for the three cards, over the whole team. */
    private function stats(): array
    {
        $status = collect($this->counts($this->base(), $this->statusSql(), self::STATUS_KEYS))->pluck('count', 'value');
        $role = collect($this->counts($this->base(), self::roleSql(), self::ROLE_KEYS))->pluck('count', 'value');
        $recent = $this->base()->reorder()->toBase()
            ->whereRaw("({$this->statusSql()}) = 'active'")
            ->where('joined.joined_at', '>=', now()->subDays(30)->toDateTimeString())
            ->count();

        return [
            'total' => (int) $status->sum(),
            'active' => (int) ($status['active'] ?? 0),
            'pending' => (int) ($status['pending'] ?? 0),
            'deactivated' => (int) ($status['deactivated'] ?? 0),
            'super_admins' => (int) ($role['super-admin'] ?? 0),
            'editors' => (int) ($role['editor'] ?? 0),
            'viewers' => (int) ($role['viewer'] ?? 0),
            'joined_last_30_days' => $recent,
        ];
    }
}
