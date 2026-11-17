<?php

namespace App\Modules\ProgramImport\Jobs;

use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\BatchValidator;
use App\Modules\ProgramImport\Pipeline\FormSpecStager;
use App\Modules\ProgramImport\Registry\ImportTypeRegistry;
use Illuminate\Support\Facades\Bus;

/** Starts validation: stages form rows if needed, then one ValidateChunkJob per sheet followed by ValidateFinalizeJob (a chain). */
class ValidateBatchJob extends BatchJob
{
    protected function run(ImportBatch $batch): void
    {
        if ($batch->type === 'form') {
            (new FormSpecStager)->stage($batch);
        }
        (new BatchValidator)->begin($batch->refresh());
        $have = ImportRow::where('batch_id', $batch->id)->distinct()->pluck('sheet')->all();
        $jobs = [];
        foreach (ImportTypeRegistry::ORDER as $sheet) {
            in_array($sheet, $have, true) && $jobs[] = new ValidateChunkJob($batch->id, $sheet);
        }
        $jobs[] = new ValidateFinalizeJob($batch->id);
        Bus::chain($jobs)->onQueue(config('program_imports.queue'))->dispatch();
    }
}
