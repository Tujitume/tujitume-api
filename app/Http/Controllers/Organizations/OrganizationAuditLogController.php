<?php

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Organizations\OrganizationAuditLog;
use App\Service\Organization\AuditReadable;
use App\Service\Organization\OrganizationAudit;
use App\Support\ApiContract;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /organizations/audit-logs   who changed what in the organization (owner and super admins only).
 *   ?page=&per_page=&search=&sort=&filters[action][]=updated&filters[subject][]=program&filters[actor][]=12&filters[when]=from~to&export=1
 * Each row: when, who (name, role), what (action, record type and name), the changed fields with old and new values,
 * and for people who are not developers: names for the ids in the change, a title for unnamed records, program / round.
 */
class OrganizationAuditLogController extends Controller
{
    public const ACTIONS = ['created', 'updated', 'deleted'];

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->organization_id) {
            return response()->json(['success' => false, 'code' => 'forbidden', 'message' => 'The audit log is for organizations.'], 403);
        }
        if (! OrganizationAudit::canRead($user)) {
            return response()->json(['success' => false, 'code' => 'forbidden', 'message' => 'Only the organization owner and super admins can see the audit log.'], 403);
        }

        $base = OrganizationAuditLog::query()->where('organization_id', $user->organization_id);
        $list = ListQuery::for($base, $request)
            ->search(['actor_name', 'subject_label', 'subject_type', 'action'])
            // In the order people look: who, what kind of record, what happened, when
            ->entity('actor', 'actor_user_id', fn (array $ids) => $this->names($ids), label: 'app:auditWho', fallback: 'User')
            ->options('subject', 'subject_type', label: 'app:auditRecord', searchable: true, fallback: 'Subject type')
            ->options('action', 'action', label: 'app:auditAction', values: self::ACTIONS,
                optionLabels: ['created' => 'app:auditCreated', 'updated' => 'app:auditUpdated', 'deleted' => 'app:auditDeleted'], fallback: 'Event')
            ->dateRange('when', 'created_at', label: 'app:auditWhen', fallback: 'Date')
            ->sortable(['when' => 'created_at', 'who' => 'actor_name', 'record' => 'subject_type'], default: '-when'); // newest first

        [$rows, $page] = $list->fetch();
        $readable = (new AuditReadable)->describe($rows);
        $this->images($rows->pluck('actor_user_id')->filter()->unique()->values()->all());

        $data = [
            'items' => $rows->map(fn (OrganizationAuditLog $log) => [
                'id' => $log->id,
                // Readable names for the ids in the change, a real title for "#123" records, and the program / round
                'names' => (object) ($readable[$log->id]['names'] ?? []),
                'title' => $readable[$log->id]['title'] ?? null,
                'context' => (object) ($readable[$log->id]['context'] ?? []),
                'created_at' => $log->created_at?->toISOString(),
                'actor_user_id' => $log->actor_user_id,
                'actor_name' => $log->actor_name,
                'actor_role' => $log->actor_role,
                'actor_image' => $log->actor_user_id ? $this->imageCache[$log->actor_user_id] ?? null : null,
                'action' => $log->action,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'subject_label' => $log->subject_label,
                'changes' => $log->changes ?? (object) [],
                'changed_fields' => count($log->changes ?? []),
                'ip' => $log->ip,
            ])->values(),
            'filter_schema' => $list->filterSchema(),
            'stats' => $this->stats((int) $user->organization_id),
            'export_truncated' => $list->wantsExport() ? $list->exportTruncated() : false,
        ];
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }

        return response()->json(['success' => true, 'message' => 'Audit log retrieved', 'data' => $data], 200, [], JSON_UNESCAPED_SLASHES);
    }

    /** [id => name] of the people in the log */
    private function names(array $ids): array
    {
        return User::query()->whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'email'])
            ->mapWithKeys(fn (User $u) => [$u->id => trim($u->first_name . ' ' . $u->last_name) ?: $u->email])->all();
    }

    private array $imageCache = [];

    private function images(array $ids): array
    {
        $missing = array_diff($ids, array_keys($this->imageCache));
        if ($missing) {
            $this->imageCache += User::query()->whereIn('id', $missing)->pluck('image', 'id')->all();
        }

        return $this->imageCache;
    }

    /** Totals for the cards: all changes, the last 7 days, people who made changes, deletions */
    private function stats(int $organizationId): array
    {
        $q = fn (): Builder => OrganizationAuditLog::query()->where('organization_id', $organizationId);

        return [
            'total' => $q()->count(),
            'last_7_days' => $q()->where('created_at', '>=', now()->subDays(7))->count(),
            'people' => (int) $q()->whereNotNull('actor_user_id')->distinct()->count('actor_user_id'),
            'deleted' => $q()->where('action', 'deleted')->count(),
        ];
    }
}
