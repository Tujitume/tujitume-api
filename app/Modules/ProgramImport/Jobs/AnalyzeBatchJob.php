<?php

namespace App\Modules\ProgramImport\Jobs;

use App\Modules\ProgramImport\Mapping\AiMapper;
use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Pipeline\BatchStager;

/** Heuristic mapping always; AI refinement only when enabled and available. Works fully with AI off. */
class AnalyzeBatchJob extends BatchJob
{
    protected function run(ImportBatch $batch): void
    {
        (new BatchStager)->analyze($batch, BatchStager::stagedQuestions($batch), BatchStager::stagedFields($batch));
        $usage = (new AiMapper)->refine($batch->refresh());
        $batch->update(['ai_usage' => $usage]);
    }
}
