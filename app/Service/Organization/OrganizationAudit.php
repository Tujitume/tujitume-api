<?php

namespace App\Service\Organization;

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\User;
use App\Models\Organizations\OrganizationAuditLog;
use App\Service\Misc\ErrorLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The organization audit log: every create, update and delete an organization member (the owner or a team member)
 * makes to the organization's records, with the old and new value of each field that changed.
 *
 * Registered once in AppServiceProvider for every Eloquent model; only the models in AUDITED (programs, rounds,
 * applications, milestones, payments, monitoring, capital offers, the organization, its workspaces, team roles and
 * settings) are written. Nothing is logged for people outside an organization (applicants, entrepreneurs), for
 * console / queue work with nobody signed in, or for raw query builder writes (those bypass Eloquent).
 * Secrets (password, token, hash, key, otp) are never stored, only "[hidden]". Recording never fails the request.
 */
class OrganizationAudit
{
    /** Model classes (or namespace prefixes ending in \) whose changes are logged */
    public const AUDITED = [
        'App\\Models\\Programs\\',
        'App\\Models\\Capital\\',
        'App\\Models\\Organizations\\',
        'App\\Models\\Milestones\\',
        'App\\Models\\ProgramEmailTemplate',
        'App\\Models\\ReviewerOrder',
        'App\\Models\\ApplicationRoundHistory',
        'App\\Models\\Auth\\OrganizationUserRole',
        'App\\Models\\Auth\\UserSetting',
    ];

    /** Bookkeeping or per-person records that are not changes to the organization */
    public const SKIPPED = [
        OrganizationAuditLog::class,
        'App\\Models\\Programs\\ProgramImport',           // import drafts autosave constantly
        'App\\Models\\Programs\\ProgramWatchlist',
        'App\\Models\\Capital\\CapitalWatchlist',
        'App\\Models\\Programs\\MilestoneAgreementRead',
        'App\\Models\\Programs\\Ai\\AiProviderUsage',
        'App\\Models\\Programs\\Ai\\ApplicationDocumentExtraction',
    ];

    /** Fields that change on every save and say nothing */
    public const IGNORED_FIELDS = ['updated_at', 'created_at', 'remember_token', 'last_seen_at', 'last_used_at'];

    /**
     * Per-record fields that are one person's own choice, not a change to the organization (owner, 28 Nov): light /
     * dark mode is how the owner's own screen looks. Branding (theme, accent colour, logo) changes everyone's and stays.
     */
    public const PERSONAL_FIELDS = [
        'App\\Models\\Auth\\UserSetting' => ['mode'],
    ];

    private const SECRET = '/password|token|secret|_hash$|^hash$|api_key|apikey|otp|private_key/i';

    private const LABEL_FIELDS = ['program_title', 'round_name', 'title', 'name', 'startup_name', 'checkpoint_name', 'event_name', 'offer_title', 'display_name', 'email', 'uid'];

    private static bool $paused = false;

    /** Run work without logging it (e.g. a big seeding job) */
    public static function withoutAudit(callable $work): mixed
    {
        $before = self::$paused;
        self::$paused = true;
        try {
            return $work();
        } finally {
            self::$paused = $before;
        }
    }

    public static function audited(Model $model): bool
    {
        $class = $model::class;
        if (in_array($class, self::SKIPPED, true)) {
            return false;
        }
        foreach (self::AUDITED as $entry) {
            if (str_ends_with($entry, '\\') ? str_starts_with($class, $entry) : $class === $entry) {
                return true;
            }
        }

        return false;
    }

    /** created | updated | deleted, from the Eloquent event listener */
    public static function record(Model $model, string $action): void
    {
        if (self::$paused || ! self::audited($model)) {
            return;
        }

        try {
            $actor = Auth::user();
            if (! $actor instanceof User || ! $actor->organization_id) {
                return;
            }

            // A team member's own settings are personal; the owner's settings run the organization's workspace
            if ($model instanceof \App\Models\Auth\UserSetting) {
                $actor->loadMissing('organization');
                if ((int) $model->user_id !== (int) ($actor->organization?->owner_user_id ?? 0)) {
                    return;
                }
            }

            $changes = self::changes($model, $action);
            if ($action === 'updated' && ! $changes) {
                return;
            }

            $request = app()->bound('request') ? request() : null;
            $log = new OrganizationAuditLog([
                'organization_id' => (int) $actor->organization_id,
                'actor_user_id' => $actor->id,
                'actor_name' => trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')) ?: ($actor->display_name ?: $actor->email),
                'actor_role' => self::roleOf($actor),
                'action' => $action,
                'subject_type' => Str::snake(class_basename($model)),
                'subject_id' => is_numeric($model->getKey()) ? (int) $model->getKey() : null,
                'subject_label' => self::labelOf($model),
                'changes' => $changes ?: null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                'created_at' => now(),
            ]);
            // A record with no name of its own ("#6296") is saved under what it is about, so search finds it
            if (str_starts_with((string) $log->subject_label, '#')) {
                $log->id = 0;
                $title = (new AuditReadable)->describe(collect([$log]))[0]['title'] ?? null;
                unset($log->id);
                if ($title) {
                    $log->subject_label = Str::limit($title, 250, '');
                }
            }
            $log->save();
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['audit_model' => $model::class, 'audit_action' => $action]);
        }
    }

    /** { field: { old, new } } for what the action changed */
    public static function changes(Model $model, string $action): array
    {
        $fields = match ($action) {
            'updated' => array_keys($model->getChanges()),
            'created' => array_keys($model->getAttributes()),
            default => array_keys($model->getRawOriginal()),
        };

        $personal = self::PERSONAL_FIELDS[$model::class] ?? [];
        $changes = [];
        foreach ($fields as $field) {
            if (in_array($field, self::IGNORED_FIELDS, true) || in_array($field, $personal, true) || ($action !== 'updated' && $field === $model->getKeyName())) {
                continue;
            }
            $old = $action === 'created' ? null : self::value($field, $model->getRawOriginal($field));
            $new = $action === 'deleted' ? null : self::value($field, $model->getAttributes()[$field] ?? null);
            if ($action !== 'updated' && $old === null && $new === null) {
                continue;
            }
            if ($action === 'updated' && $old === $new) {
                continue;
            }
            $changes[$field] = ['old' => $old, 'new' => $new];
        }

        return $changes;
    }

    private static function value(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match(self::SECRET, $field)) {
            return '[hidden]';
        }
        if (is_string($value) && ($value[0] ?? '') !== '' && in_array($value[0], ['{', '['], true)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return strlen((string) $json) > 4000 ? Str::limit((string) $json, 4000) : $value;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        return Str::limit((string) $value, 2000);
    }

    private static function labelOf(Model $model): ?string
    {
        if ($model instanceof OrganizationUserRole) {
            $user = User::find($model->user_id);
            if ($user) {
                return Str::limit(trim($user->first_name . ' ' . $user->last_name) ?: (string) $user->email, 250, '');
            }
        }
        $attributes = $model->getAttributes() + $model->getRawOriginal();
        foreach (self::LABEL_FIELDS as $field) {
            $value = $attributes[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), 250, '');
            }
        }

        return $model->getKey() ? '#' . $model->getKey() : null;
    }

    /** The owner, or a super admin whose membership is active (a revoked or pending one cannot read the log) */
    public static function canRead(User $user): bool
    {
        if (! $user->organization_id) {
            return false;
        }
        $user->loadMissing('organization');
        if ($user->organization && (int) $user->organization->owner_user_id === (int) $user->id) {
            return true;
        }

        return OrganizationUserRole::query()
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['super_admin', 'super-admin']))
            ->exists();
    }

    /** owner | super_admin | editor | viewer | internal_reviewer */
    public static function roleOf(User $user): ?string
    {
        $user->loadMissing('organization');
        if ($user->organization && (int) $user->organization->owner_user_id === (int) $user->id) {
            return 'owner';
        }

        return OrganizationUserRole::query()
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->with('role:id,name')
            ->first()?->role?->name;
    }
}
