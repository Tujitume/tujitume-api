<?php

namespace App\Modules\ProgramImport\Rollback;

use App\Models\Auth\User;
use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportLink;
use App\Modules\ProgramImport\Models\ImportRecord;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Models\ImportUser;
use App\Modules\ProgramImport\Silent\ImportContext;
use Illuminate\Support\Facades\DB;

/** Undoes a batch from its ledger, newest first, in one transaction. Call RollbackGuard first. */
class Rollbacker
{
    public function run(ImportBatch $batch): void
    {
        ImportContext::silently(function () use ($batch) {
            DB::transaction(function () use ($batch) {
                foreach (ImportRecord::where('batch_id', $batch->id)->orderByDesc('id')->get() as $r) {
                    if ($r->record_type === 'file') {
                        \Illuminate\Support\Facades\Storage::disk($r->before['disk'] ?? 's3')->delete($r->before['path'] ?? '');

                        continue;
                    }
                    $class = $r->record_type;
                    $m = $class::find($r->record_id);
                    if (! $m) {
                        continue;
                    }
                    if ($r->action === 'created') {
                        if ($m instanceof \App\Models\Auth\User && ($refs = \App\Modules\ProgramImport\Support\UserReferences::foreign($m->id))) {
                            throw new \RuntimeException('An imported account gained other data ('.implode(', ', $refs).') while the rollback ran; nothing was undone.');
                        }
                        $m->delete();
                    } elseif ($r->action === 'updated' && $r->before) {
                        $m->forceFill($r->before)->save();
                    }
                }
                ImportUser::where('batch_id', $batch->id)->delete();
                ImportLink::where('batch_id', $batch->id)->delete();
                ImportRow::where('batch_id', $batch->id)->whereIn('status', ['imported'])->update(['status' => 'skipped', 'target_type' => null, 'target_id' => null]);
                $batch->update(['status' => 'rolled_back', 'rolled_back_at' => now()]);
            });
        });
    }
}
