<?php

namespace App\Modules\ProgramImport\Jobs;

use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Pipeline\BatchValidator;
use App\Modules\ProgramImport\Pipeline\ReadinessService;

/** After every sheet is normalized: duplicates, cross-sheet references, totals, then the readiness result. */
class ValidateFinalizeJob extends BatchJob
{
    protected function run(ImportBatch $batch): void
    {
        (new BatchValidator)->finish($batch);
        (new ReadinessService)->run($batch->refresh());
    }
}
