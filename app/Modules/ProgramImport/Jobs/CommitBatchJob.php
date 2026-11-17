<?php

namespace App\Modules\ProgramImport\Jobs;

use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Pipeline\CommitRunner;
use App\Modules\ProgramImport\Pipeline\ReadinessService;
use Illuminate\Support\Facades\Bus;

/**
 * Starts the commit. All-or-nothing runs as one transaction in this job; otherwise a chain of CommitChunkJob (one per sheet,
 * dependency order) ends with FinalizeBatchJob. A retry never writes twice: imported rows are no longer 'valid'.
 */
class CommitBatchJob extends BatchJob
{
    protected function run(ImportBatch $batch): void
    {
        if ($batch->status === 'importing' || in_array($batch->status, ['completed', 'completed_with_errors'], true)) {
            return;
        }
        $res = (new ReadinessService)->run($batch);
        if (! $res['ready']) {
            $batch->update(['status' => 'has_errors']);

            return;
        }
        $runner = new CommitRunner;
        if (CommitContext::optionsFor($batch)['mode'] === 'all_or_nothing') {
            $runner->run($batch->refresh());

            return;
        }
        $runner->begin($batch);
        $jobs = array_map(fn ($sheet) => new CommitChunkJob($batch->id, $sheet), $runner->sheetsWithRows($batch));
        $jobs[] = new FinalizeBatchJob($batch->id);
        Bus::chain($jobs)->onQueue(config('program_imports.queue'))->dispatch();
    }
}
