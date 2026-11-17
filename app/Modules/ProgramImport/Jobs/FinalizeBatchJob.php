<?php

namespace App\Modules\ProgramImport\Jobs;

use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Pipeline\CommitRunner;

/** Derived data, ledger snapshots and the final batch status. Skipped when the batch already finished. */
class FinalizeBatchJob extends BatchJob
{
    protected function run(ImportBatch $batch): void
    {
        if (in_array($batch->status, ['completed', 'completed_with_errors'], true)) {
            return;
        }
        (new CommitRunner)->finalize($batch);
    }
}
