<?php

namespace App\Service\Organization;

use App\Models\Organizations\OrganizationAuditLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes audit log rows readable for people who are not developers: the ids in a change ("round_id: 1008",
 * "reviewer_id: 8") become names ("Application review", "Owen Smith"), a record logged only as "#6296" gets a real
 * title ("Maji Safi Water Kiosks"), and every row says which program and round it belongs to.
 *
 * Works on one page (or one export chunk) at a time, with one query per kind of record, never one per row.
 *   names:   { field: { old: "name"|null, new: "name"|null } } for each id field that could be named
 *   title:   a readable name for the record, or null to keep subject_label
 *   context: { program: "…"|null, round: "…"|null, application: "…"|null }
 */
class AuditReadable
{
    /** Which table and column names each kind of id */
    private const KINDS = [
        'user' => ['users', null],
        'program' => ['programs', 'program_title'],
        'round' => ['program_rounds', 'round_name'],
        'application' => ['program_applications', 'startup_name'],
        'milestone' => ['program_milestones', 'title'],
        'supplier' => ['supplier_directories', 'legal_name'],
        'milestone_supplier' => ['milestone_suppliers', null],
        'organization' => ['organizations', null],
        'industry' => ['program_industries', 'name'],
        'role' => ['roles', 'name'],
        'round_reviewer' => ['round_reviewers', null],
    ];

    /** Id fields and what they point to (supplier_id is resolved per record type, see kindOf) */
    private const FIELDS = [
        'user_id' => 'user', 'reviewer_id' => 'user', 'program_owner_id' => 'user', 'invited_by_user_id' => 'user',
        'owner_user_id' => 'user', 'approved_by' => 'user', 'completion_approved_by' => 'user', 'added_by' => 'user',
        'created_by' => 'user', 'updated_by' => 'user', 'assigned_by' => 'user', 'actor_user_id' => 'user',
        'program_id' => 'program',
        'round_id' => 'round', 'current_round_id' => 'round', 'program_round_id' => 'round',
        'application_id' => 'application', 'app_id' => 'application',
        'milestone_id' => 'milestone',
        'supplier_id' => 'supplier',
        'organization_id' => 'organization',
        'program_industry_id' => 'industry',
        'role_id' => 'role',
        'round_reviewer_id' => 'round_reviewer',
    ];

    /** Records with no name of their own: the table holding them and the fields that tell what they are about */
    private const SUBJECTS = [
        'application_score' => ['application_scores', ['application_id', 'round_id', 'reviewer_id']],
        'reviewer_application_assignment' => ['reviewer_application_assignments', ['application_id', 'round_id', 'reviewer_id']],
        'application_round_history' => ['application_round_history', ['application_id', 'round_id']],
        'round_reviewer' => ['round_reviewers', ['round_id', 'user_id']],
        'reviewer_order' => ['reviewer_orders', ['program_id', 'round_id', 'reviewer_id']],
        'program_wallet' => ['program_wallets', ['program_id', 'application_id']],
        'round_custom_question' => ['round_custom_questions', ['round_id', 'question_text']],
        'milestone_supplier' => ['milestone_suppliers', ['milestone_id', 'supplier_id']],
        'milestone_budget_item' => ['milestone_budget_items', ['milestone_id', 'supplier_id', 'item_description']],
        'program_milestone' => ['program_milestones', ['app_id']],
        'program_application' => ['program_applications', ['program_id']],
        'program_round' => ['program_rounds', ['program_id']],
    ];

    /** Which field gives the record its title */
    private const TITLE_FROM = [
        'application_score' => 'application_id',
        'reviewer_application_assignment' => 'application_id',
        'application_round_history' => 'application_id',
        'round_reviewer' => 'user_id',
        'reviewer_order' => 'reviewer_id',
        'program_wallet' => 'program_id',
        'round_custom_question' => 'question_text',
        'milestone_supplier' => 'supplier_id',
        'milestone_budget_item' => 'item_description',
    ];

    /** @var array<string, array<int, string>> kind => [id => name] */
    private array $names = [];

    /**
     * @param  Collection<int, OrganizationAuditLog>  $logs
     * @return array<int, array{names: array, title: ?string, context: array}>  keyed by log id
     */
    public function describe(Collection $logs): array
    {
        $links = $this->subjectLinks($logs);

        // Every id worth naming: the ones in the changes and the ones behind each record
        $wanted = [];
        foreach ($logs as $log) {
            foreach ($this->idFields($log, $links) as [$kind, $id]) {
                $wanted[$kind][$id] = true;
            }
        }
        $this->load($wanted);

        // Rounds and applications lead to their program, milestones to their application
        $this->loadParents();

        $out = [];
        foreach ($logs as $log) {
            $out[$log->id] = $this->row($log, $links[$log->subject_type][$log->subject_id] ?? []);
        }

        return $out;
    }

    /** subject_type => [subject_id => [field => value]] from the record itself (it may have been deleted) */
    private function subjectLinks(Collection $logs): array
    {
        $links = [];
        foreach ($logs->groupBy('subject_type') as $type => $group) {
            if (! isset(self::SUBJECTS[$type]) || ! Schema::hasTable(self::SUBJECTS[$type][0])) {
                continue;
            }
            [$table, $fields] = self::SUBJECTS[$type];
            $ids = $group->pluck('subject_id')->filter()->unique()->values()->all();
            if (! $ids) {
                continue;
            }
            $links[$type] = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (DB::table($table)->whereIn('id', $chunk)->get(array_merge(['id'], $fields)) as $row) {
                    $links[$type][$row->id] = (array) $row;
                }
            }
        }

        return $links;
    }

    /** [kind, id] pairs a row needs */
    private function idFields(OrganizationAuditLog $log, array $links): array
    {
        $pairs = [];
        foreach ((array) ($log->changes ?? []) as $field => $change) {
            $kind = $this->kindOf($log->subject_type, (string) $field);
            if (! $kind) {
                continue;
            }
            foreach (['old', 'new'] as $side) {
                $id = $change[$side] ?? null;
                if (is_numeric($id) && (int) $id > 0) {
                    $pairs[] = [$kind, (int) $id];
                }
            }
        }
        foreach ($links[$log->subject_type][$log->subject_id] ?? [] as $field => $id) {
            $kind = $field === 'id' ? null : $this->kindOf($log->subject_type, (string) $field);
            if ($kind && is_numeric($id) && (int) $id > 0) {
                $pairs[] = [$kind, (int) $id];
            }
        }
        if ($log->subject_type === 'program' && $log->subject_id) {
            $pairs[] = ['program', (int) $log->subject_id];
        }
        if ($log->subject_type === 'program_round' && $log->subject_id) {
            $pairs[] = ['round', (int) $log->subject_id];
        }
        if ($log->subject_type === 'program_application' && $log->subject_id) {
            $pairs[] = ['application', (int) $log->subject_id];
        }

        return $pairs;
    }

    private function kindOf(string $type, string $field): ?string
    {
        // A budget line's supplier is the milestone's supplier entry, not the directory entry
        if ($field === 'supplier_id' && $type === 'milestone_budget_item') {
            return 'milestone_supplier';
        }

        return self::FIELDS[$field] ?? null;
    }

    /** @var array<string, array<int, array>> kind => [id => parent ids] */
    private array $parents = [];

    /** @param array<string, array<int, true>> $wanted */
    private function load(array $wanted): void
    {
        foreach ($wanted as $kind => $ids) {
            $ids = array_values(array_diff(array_keys($ids), array_keys($this->names[$kind] ?? [])));
            if (! $ids) {
                continue;
            }
            [$table, $column] = self::KINDS[$kind];
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach ($this->fetch($kind, $table, $column, $chunk) as $id => $name) {
                    $this->names[$kind][$id] = $name;
                }
            }
        }
    }

    /** [id => name] for one kind */
    private function fetch(string $kind, string $table, ?string $column, array $ids): array
    {
        $query = DB::table($table)->whereIn('id', $ids);

        return match ($kind) {
            'user' => $query->get(['id', 'first_name', 'last_name', 'email'])
                ->mapWithKeys(fn ($u) => [$u->id => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: (string) $u->email])->all(),
            'organization' => $query->get(['id', 'display_name', 'name'])
                ->mapWithKeys(fn ($o) => [$o->id => (string) ($o->display_name ?: $o->name)])->all(),
            'round' => $query->get(['id', 'round_name', 'round_number', 'program_id'])
                ->mapWithKeys(function ($r) {
                    $this->parents['round'][$r->id] = ['program' => $r->program_id];

                    return [$r->id => (string) ($r->round_name ?: 'Round ' . $r->round_number)];
                })->all(),
            'application' => $query->get(['id', 'startup_name', 'contact_person_name', 'program_id'])
                ->mapWithKeys(function ($a) {
                    $this->parents['application'][$a->id] = ['program' => $a->program_id];

                    return [$a->id => (string) ($a->startup_name ?: $a->contact_person_name)];
                })->all(),
            'milestone' => $query->get(['id', 'title', 'app_id'])
                ->mapWithKeys(function ($m) {
                    $this->parents['milestone'][$m->id] = ['application' => $m->app_id];

                    return [$m->id => (string) $m->title];
                })->all(),
            'milestone_supplier' => DB::table('milestone_suppliers as ms')
                ->leftJoin('supplier_directories as sd', 'sd.id', '=', 'ms.supplier_id')
                ->whereIn('ms.id', $ids)->get(['ms.id', 'sd.legal_name', 'sd.contact_person', 'sd.email'])
                ->mapWithKeys(fn ($s) => [$s->id => (string) ($s->legal_name ?: $s->contact_person ?: $s->email)])->all(),
            'supplier' => $query->get(['id', 'legal_name', 'contact_person', 'email'])
                ->mapWithKeys(fn ($s) => [$s->id => (string) ($s->legal_name ?: $s->contact_person ?: $s->email)])->all(),
            'round_reviewer' => DB::table('round_reviewers as rr')->leftJoin('users as u', 'u.id', '=', 'rr.user_id')
                ->whereIn('rr.id', $ids)->get(['rr.id', 'u.first_name', 'u.last_name', 'u.email'])
                ->mapWithKeys(fn ($u) => [$u->id => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: (string) $u->email])->all(),
            default => $query->pluck($column, 'id')->map(fn ($v) => (string) $v)->all(),
        };
    }

    /** Programs behind rounds and applications, applications behind milestones (two short passes) */
    private function loadParents(): void
    {
        for ($pass = 0; $pass < 2; $pass++) {
            $wanted = [];
            foreach ($this->parents as $byId) {
                foreach ($byId as $parents) {
                    foreach ($parents as $kind => $id) {
                        if ($id && ! isset($this->names[$kind][$id])) {
                            $wanted[$kind][(int) $id] = true;
                        }
                    }
                }
            }
            if (! $wanted) {
                return;
            }
            $this->load($wanted);
        }
    }

    private function nameOf(string $kind, mixed $id): ?string
    {
        if (! is_numeric($id)) {
            return null;
        }
        $name = $this->names[$kind][(int) $id] ?? null;

        return $name !== null && trim($name) !== '' ? $name : null;
    }

    private function row(OrganizationAuditLog $log, array $link): array
    {
        $changes = (array) ($log->changes ?? []);

        // Names for the id fields in the change
        $names = [];
        foreach ($changes as $field => $change) {
            $kind = $this->kindOf($log->subject_type, (string) $field);
            if (! $kind) {
                continue;
            }
            $old = $this->nameOf($kind, $change['old'] ?? null);
            $new = $this->nameOf($kind, $change['new'] ?? null);
            if ($old !== null || $new !== null) {
                $names[$field] = ['old' => $old, 'new' => $new];
            }
        }

        // What the record is about: the change's own values first (a deleted record has nothing left), then the record
        $value = function (string $field) use ($changes, $link) {
            $change = $changes[$field] ?? null;

            return $change['new'] ?? $change['old'] ?? $link[$field] ?? null;
        };

        $roundId = $value('round_id') ?? $value('current_round_id') ?? ($log->subject_type === 'program_round' ? $log->subject_id : null);
        $applicationId = $value('application_id') ?? $value('app_id') ?? ($log->subject_type === 'program_application' ? $log->subject_id : null);
        if (! $applicationId && ($milestoneId = $value('milestone_id'))) {
            $applicationId = $this->parents['milestone'][(int) $milestoneId]['application'] ?? null;
        }
        $programId = $value('program_id')
            ?? ($log->subject_type === 'program' ? $log->subject_id : null)
            ?? ($roundId ? $this->parents['round'][(int) $roundId]['program'] ?? null : null)
            ?? ($applicationId ? $this->parents['application'][(int) $applicationId]['program'] ?? null : null);

        $context = [
            'program' => $log->subject_type === 'program' ? null : $this->nameOf('program', $programId),
            'round' => $log->subject_type === 'program_round' ? null : $this->nameOf('round', $roundId),
            'application' => $log->subject_type === 'program_application' ? null : $this->nameOf('application', $applicationId),
        ];

        return ['names' => $names, 'title' => $this->title($log, $value), 'context' => array_filter($context)];
    }

    /** A real name for records logged as "#123" */
    private function title(OrganizationAuditLog $log, callable $value): ?string
    {
        $label = (string) ($log->subject_label ?? '');
        if ($label !== '' && ! str_starts_with($label, '#')) {
            return null;
        }
        $field = self::TITLE_FROM[$log->subject_type] ?? null;
        if (! $field) {
            return null;
        }
        $raw = $value($field);
        if (in_array($field, ['question_text', 'item_description'], true)) {
            return is_string($raw) && trim($raw) !== '' ? mb_strimwidth(trim($raw), 0, 120, '…') : null;
        }

        return $this->nameOf($this->kindOf($log->subject_type, $field) ?? '', $raw);
    }
}
