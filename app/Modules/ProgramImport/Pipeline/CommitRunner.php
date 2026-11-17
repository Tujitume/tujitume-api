<?php

namespace App\Modules\ProgramImport\Pipeline;

use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\Program;
use App\Models\Programs\Rounds\ProgramRound;
use App\Modules\ProgramImport\Importers\Importer;
use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportRecord;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Registry\ImportTypeRegistry;
use App\Modules\ProgramImport\Silent\ImportContext;
use Illuminate\Support\Facades\DB;

/** Writes staged rows in dependency order, in silent mode, one transaction per row (or one for the whole batch). */
class CommitRunner
{
    public const LANDED = [
        Program::class => ['status'],
        ProgramRound::class => ['status'],
        ProgramApplication::class => ['status', 'round_status', 'current_round_id', 'funding_setup_status', 'escrow_funded'],
        ProgramMilestone::class => ['status', 'fund_release_status', 'is_template'],
    ];

    /** One-shot commit (tests, all-or-nothing, small batches). The jobs run begin, one chunk per sheet, then finalize. */
    public function run(ImportBatch $batch): void
    {
        ImportContext::silently(function () use ($batch) {
            if (CommitContext::optionsFor($batch)['mode'] === 'all_or_nothing') {
                $this->allOrNothing($batch);

                return;
            }
            $this->begin($batch);
            foreach ($this->sheetsWithRows($batch) as $sheet) {
                $this->runSheet($batch->refresh(), $sheet);
            }
            $this->finalize($batch->refresh());
        });
    }

    /** @return list<string> sheets that still have rows to write, in dependency order */
    public function sheetsWithRows(ImportBatch $batch): array
    {
        $have = ImportRow::where('batch_id', $batch->id)->where('status', 'valid')->distinct()->pluck('sheet')->all();

        return array_values(array_filter(ImportTypeRegistry::ORDER, fn ($s) => in_array($s, $have, true)));
    }

    public function begin(ImportBatch $batch): void
    {
        $stats = $batch->stats ?? [];
        $stats['commit'] = ['imported' => 0, 'failed' => 0, 'skipped' => ImportRow::where('batch_id', $batch->id)->where('status', 'skipped')->count(), 'by_sheet' => []];
        $batch->update(['status' => 'importing', 'started_at' => now(), 'error_summary' => null, 'stats' => $stats]);
    }

    /** One sheet, per-row transactions. Restartable: rows already imported are not 'valid' any more, so a retry writes nothing twice. */
    public function runSheet(ImportBatch $batch, string $sheet): void
    {
        ImportContext::silently(function () use ($batch, $sheet) {
            $c = CommitContext::resume($batch);
            $c->targetRound = $this->targetRound($batch, $c);
            $importer = Importer::for($sheet);
            $stats = $batch->stats ?? [];
            ImportRow::where('batch_id', $batch->id)->where('sheet', $sheet)->where('status', 'valid')->orderBy('id')->chunkById(200, function ($rows) use ($importer, $c, &$stats, $sheet) {
                foreach ($rows as $row) {
                    $snap = $c->checkpoint();
                    try {
                        [$type, $id] = DB::transaction(fn () => $importer->import($row, $c));
                        $row->forceFill(['status' => 'imported', 'target_type' => $type, 'target_id' => $id])->save();
                        $stats['commit']['imported']++;
                        $stats['commit']['by_sheet'][$sheet] = ($stats['commit']['by_sheet'][$sheet] ?? 0) + 1;
                    } catch (\Throwable $e) {
                        $c->restore($snap);
                        if (! $e instanceof ImportRowException) {
                            report($e);
                        }
                        $row->forceFill(['status' => 'failed', 'errors' => ['row' => $e instanceof ImportRowException ? $e->getMessage() : 'Unexpected error while saving this row.']])->save();
                        $stats['commit']['failed']++;
                    }
                }
            });
            $batch->update(['stats' => $stats]);
        });
    }

    /** Derived data (unlocking, totals, program status), ledger snapshots and the final status. */
    public function finalize(ImportBatch $batch): void
    {
        ImportContext::silently(function () use ($batch) {
            $c = CommitContext::resume($batch);
            $c->targetRound = $this->targetRound($batch, $c);
            DB::transaction(function () use ($batch, $c) {
                $this->finishAll($c);
                $this->refreshSnapshots($batch);
            });
            $stats = $batch->refresh()->stats ?? [];
            $stats['commit']['program_id'] = $c->program?->id;
            $stats['commit']['failure_reasons'] = $this->failureReasons($batch);
            $batch->update(['status' => ($stats['commit']['failed'] ?? 0) ? 'completed_with_errors' : 'completed', 'completed_at' => now(), 'stats' => $stats]);
        });
    }

    /** The few most common reasons rows could not be saved, with counts, so the person sees why at a glance. */
    private function failureReasons(ImportBatch $batch): array
    {
        $by = [];
        foreach (ImportRow::where('batch_id', $batch->id)->where('status', 'failed')->get(['sheet', 'errors']) as $r) {
            $msg = (string) ($r->errors['row'] ?? 'Could not be saved');
            $k = $r->sheet.'|'.$msg;
            $by[$k] = ['sheet' => $r->sheet, 'message' => $msg, 'count' => ($by[$k]['count'] ?? 0) + 1];
        }
        usort($by, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_slice(array_values($by), 0, 6);
    }

    private function finishAll(CommitContext $c): void
    {
        foreach (ImportTypeRegistry::ORDER as $sheet) {
            $sheet !== 'program' && Importer::for($sheet)->finish($c);
        }
        Importer::for('program')->finish($c);
    }

    /** All-or-nothing: one transaction for everything; any failed row rolls the whole batch back. */
    private function allOrNothing(ImportBatch $batch): void
    {
        $this->begin($batch);
        $c = CommitContext::resume($batch);
        $c->targetRound = $this->targetRound($batch, $c);
        $stats = $batch->refresh()->stats;
        try {
            DB::transaction(function () use ($batch, $c, &$stats) {
                foreach (ImportTypeRegistry::ORDER as $sheet) {
                    $importer = Importer::for($sheet);
                    ImportRow::where('batch_id', $batch->id)->where('sheet', $sheet)->where('status', 'valid')->orderBy('id')->chunkById(200, function ($rows) use ($importer, $c, &$stats, $sheet) {
                        foreach ($rows as $row) {
                            $snap = $c->checkpoint();
                            try {
                                [$type, $id] = $importer->import($row, $c);
                            } catch (\Throwable $e) {
                                $c->restore($snap);
                                if (! $e instanceof ImportRowException) {
                                    report($e);
                                }
                                throw new ImportRowException("Row {$row->row_number} of $sheet: ".($e instanceof ImportRowException ? $e->getMessage() : 'Unexpected error while saving this row.'));
                            }
                            $row->forceFill(['status' => 'imported', 'target_type' => $type, 'target_id' => $id])->save();
                            $stats['commit']['imported']++;
                            $stats['commit']['by_sheet'][$sheet] = ($stats['commit']['by_sheet'][$sheet] ?? 0) + 1;
                        }
                    });
                }
                $this->finishAll($c);
                $this->refreshSnapshots($batch);
            });
        } catch (\Throwable $e) {
            $batch->update(['status' => 'failed', 'completed_at' => now(), 'error_summary' => ['message' => $e instanceof ImportRowException ? $e->getMessage() : 'The import failed and nothing was saved.'], 'stats' => $stats]);
            if (! $e instanceof ImportRowException) {
                report($e);
            }

            return;
        }
        $stats['commit']['program_id'] = $c->program?->id;
        $batch->update(['status' => 'completed', 'completed_at' => now(), 'stats' => $stats]);
    }

    /** Highest current-round number among staged applications: that is the round that stays live at review/finalization. */
    private function targetRound(ImportBatch $batch, CommitContext $c): ?int
    {
        if (! in_array($c->landing->startPoint(), ['review', 'finalization'], true)) {
            return null;
        }
        $max = ImportRow::where('batch_id', $batch->id)->where('sheet', 'applications')->where('status', 'valid')->get()->max(fn ($r) => (int) ($r->normalized['round_number'] ?? 1));

        return $max ?: null;
    }

    /** Created records keep their landed state so the rollback guard can tell later user changes from the import's own. */
    private function refreshSnapshots(ImportBatch $batch): void
    {
        foreach (self::LANDED as $class => $attrs) {
            ImportRecord::where('batch_id', $batch->id)->where('record_type', $class)->where('action', 'created')->each(function (ImportRecord $r) use ($class, $attrs) {
                $m = $class::find($r->record_id);
                $m && $r->update(['before' => ['_after' => array_intersect_key($m->getAttributes(), array_flip($attrs))]]);
            });
        }
    }
}
