<?php

namespace App\Modules\ProgramImport\Jobs;

use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Pipeline\BatchStager;

class ParseBatchJob extends BatchJob
{
    protected function run(ImportBatch $batch): void
    {
        (new BatchStager)->parse($batch);
    }
}
