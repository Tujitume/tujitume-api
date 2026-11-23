<?php

namespace App\Modules\ProgramImport\Pipeline;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Programs\Program;
use App\Models\Programs\Rounds\ProgramRound;
use App\Modules\ProgramImport\Landing\LandingDefinition;
use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportLink;
use App\Modules\ProgramImport\Models\ImportRecord;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Registry\StartPointCatalog;
use App\Modules\ProgramImport\Support\Refs;
use Illuminate\Database\Eloquent\Model;

/** Shared state of one commit: who owns what, the landing rules, reference lookups and the ledger. */
class CommitContext
{
    public array $opts;

    public LandingDefinition $landing;

    public ?Program $program = null;

    public ?int $targetRound = null;

    public bool $allMilestonesDone = false;

    public array $touchedPrograms = [];

    public array $touchedApplications = [];

    private array $cache = [];

    private array $beforeSaved = [];

    private array $createdKeys = [];

    private ?User $owner = null;

    public function __construct(public ImportBatch $batch)
    {
        $this->opts = BatchValidator::options($batch);
        $this->landing = StartPointCatalog::landing(ReadinessService::startPoint($batch));
        if ($batch->program_id) {
            $this->program = Program::find($batch->program_id);
        }
    }

    public static function optionsFor(ImportBatch $batch): array
    {
        return BatchValidator::options($batch);
    }

    /**
     * Rebuild the in-memory state for a later chunk job: the batch's program (from the ledger) and the programs/applications
     * touched so far (from the rows already imported), so every chunk and the finalize step see the same picture.
     */
    public static function resume(ImportBatch $batch): self
    {
        $c = new self($batch);
        if (! $c->program) {
            $pid = ImportRecord::where('batch_id', $batch->id)->where('record_type', Program::class)->where('action', 'created')->value('record_id')
                ?? \App\Modules\ProgramImport\Models\ImportRow::where('batch_id', $batch->id)->where('sheet', 'program')->where('status', 'imported')->value('target_id');
            $c->program = $pid ? Program::find($pid) : null;
        }
        $rows = \App\Modules\ProgramImport\Models\ImportRow::where('batch_id', $batch->id)->where('status', 'imported');
        foreach ((clone $rows)->where('target_type', 'application')->pluck('target_id') as $id) {
            $c->touchedApplications[(int) $id] = true;
            $pid = \App\Models\Programs\ProgramApplication::where('id', $id)->value('program_id');
            $pid && $c->touchedPrograms[(int) $pid] = true;
        }
        foreach ((clone $rows)->where('target_type', 'program')->pluck('target_id') as $id) {
            $c->touchedPrograms[(int) $id] = true;
        }
        if ($c->program && (clone $rows)->exists()) {
            $c->touchedPrograms[$c->program->id] = true;
        }

        return $c;
    }

    /** Snapshot/restore of in-memory state around a per-row transaction, so a rolled-back row leaves no stale cache. */
    public function checkpoint(): array
    {
        return [$this->cache, $this->beforeSaved, $this->createdKeys, $this->touchedPrograms, $this->touchedApplications, $this->program?->id];
    }

    public function restore(array $s): void
    {
        [$this->cache, $this->beforeSaved, $this->createdKeys, $this->touchedPrograms, $this->touchedApplications, $pid] = $s;
        $this->program = $pid ? Program::find($pid) : null;
    }

    private ?array $awardeeRefs = null;

    /** References of the awardees in this batch that point at an application row (application_ref given). */
    private function awardeeRefs(): array
    {
        return $this->awardeeRefs ??= ImportRow::where('batch_id', $this->batch->id)->where('sheet', 'winners')->whereNotNull('external_ref')
            ->where('external_ref', 'not like', 'winner:%')->pluck('external_ref')->flip()->all();
    }

    public function hasLinkedAwardees(): bool
    {
        return $this->awardeeRefs() !== [];
    }

    public function isAwardee(?string $ref): bool
    {
        return $ref !== null && isset($this->awardeeRefs()[$ref]);
    }

    public function owner(): User
    {
        return $this->owner ??= Organization::findOrFail($this->batch->organization_id)->owner;
    }

    public function organizationId(): int
    {
        return (int) $this->batch->organization_id;
    }

    /** Program a row belongs to: its program_ref, else the batch's program, else the one program created in this batch. */
    public function programFor(array $v): Program
    {
        if (! empty($v['program_ref']) && ($id = $this->find('program', $v['program_ref'], null))) {
            return Program::findOrFail($id);
        }
        if ($this->program) {
            return $this->program;
        }
        throw new ImportRowException('The program for this row could not be found.');
    }

    /** Linked entity id for a reference ("pid:ref" for program-scoped types). Never guesses between programs. */
    public function find(string $entity, ?string $ref, ?int $programId): ?int
    {
        if ($ref === null || $ref === '') {
            return null;
        }
        $programId ??= $this->program?->id;
        $key = $entity.'|'.$programId.'|'.$ref;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $q = ImportLink::where('organization_id', $this->organizationId())->where('entity_type', $entity);
        $stored = Refs::stored($entity, $ref, $programId);
        $id = (clone $q)->where('external_ref', $stored)->value('entity_id');
        if (! $id && Refs::scoped($entity) && ! $programId) {
            $ids = (clone $q)->where(fn ($w) => $w->where('external_ref', $ref)->orWhere('external_ref', 'like', '%:'.str_replace(['%', '_'], ['\%', '\_'], $ref)))->pluck('entity_id')->unique();
            if ($ids->count() > 1) {
                throw new ImportRowException("The reference \"$ref\" matches more than one program. Import into one program at a time.");
            }
            $id = $ids->first();
        }

        return $this->cache[$key] = $id ? (int) $id : null;
    }

    /** Stored files are part of the ledger so a rollback can delete them. */
    public function recordFile(string $path): void
    {
        ImportRecord::create(['batch_id' => $this->batch->id, 'record_type' => 'file', 'record_id' => 0, 'action' => 'created', 'before' => ['path' => $path, 'disk' => 's3']]);
    }

    private ?\App\Modules\ProgramImport\Support\AttachmentBag $bag = null;

    public function attachments(): ?\App\Modules\ProgramImport\Support\AttachmentBag
    {
        $path = $this->batch->options['attachments_path'] ?? null;

        return $path ? ($this->bag ??= new \App\Modules\ProgramImport\Support\AttachmentBag($path)) : null;
    }

    public function findOrFail(string $entity, ?string $ref, ?int $programId = null): int
    {
        return $this->find($entity, $ref, $programId) ?? throw new ImportRowException("Unknown $entity reference \"$ref\".");
    }

    public function link(string $entity, Model $m, ?string $ref, ?int $programId = null): void
    {
        if (! $ref) {
            return;
        }
        $stored = Refs::stored($entity, $ref, $programId);
        ImportLink::create(['organization_id' => $this->organizationId(), 'entity_type' => $entity, 'entity_id' => $m->getKey(), 'external_ref' => mb_substr($stored, 0, 191), 'batch_id' => $this->batch->id, 'created_at' => now()]);
        $this->cache[$entity.'|'.($programId ?? $this->program?->id).'|'.$ref] = (int) $m->getKey();
    }

    /** Ledger. For updates the first before-snapshot per record wins; for creates, $after records the landed state. */
    public function record(Model $m, string $action, array $before = [], ?array $after = null): void
    {
        $key = get_class($m).'#'.$m->getKey();
        if ($action === 'created') {
            $this->createdKeys[$key] = true;
        }
        if ($action === 'updated') {
            if (isset($this->createdKeys[$key])) {
                return; // created by this batch: rollback deletes it; the landed snapshot is refreshed at the end of the commit
            }
            if (isset($this->beforeSaved[$key])) {
                return;
            }
            $this->beforeSaved[$key] = true;
        }
        if ($action === 'created' && $after !== null) {
            $before = ['_after' => $after];
        }
        ImportRecord::create(['batch_id' => $this->batch->id, 'record_type' => get_class($m), 'record_id' => $m->getKey(), 'action' => $action, 'before' => $before ?: null]);
    }

    /** Update a pre-existing/created model through the ledger (first snapshot only). */
    public function updateModel(Model $m, array $changes): void
    {
        $before = array_intersect_key($m->getOriginal(), $changes);
        $m->forceFill($changes)->save();
        $this->record($m, 'updated', $before);
    }

    public function createdInBatch(Model $m): bool
    {
        if (isset($this->createdKeys[get_class($m).'#'.$m->getKey()])) {
            return true;
        }

        return ImportRecord::where('batch_id', $this->batch->id)->where('record_type', get_class($m))->where('record_id', $m->getKey())->where('action', 'created')->exists();
    }

    public function finalRound(Program $p): ProgramRound
    {
        $round = $p->rounds()->orderByDesc('round_number')->first();
        if ($round) {
            return $round;
        }
        $round = ProgramRound::create([
            'program_id' => $p->id, 'round_name' => 'Imported awardees', 'round_number' => max(1, (int) $p->total_rounds),
            'rubric_mode' => 'simple_total', 'assignment_type' => 'owner_only', 'assignment_method' => 'manual',
            'advancement_mode' => 'manual', 'status' => 'finalized',
        ]);
        $this->record($round, 'created', [], ['status' => 'finalized']);
        $this->link('round', $round, 'imported-awardees', $p->id);

        return $round;
    }
}
