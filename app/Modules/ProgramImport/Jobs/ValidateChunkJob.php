<?php

namespace App\Modules\ProgramImport\Jobs;

use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Pipeline\BatchValidator;

/** Normalizes one sheet's rows. Restartable: each row's previous result is overwritten. */
class ValidateChunkJob extends BatchJob
{
    public function __construct(int $batchId, public string $sheet)
    {
        parent::__construct($batchId);
    }

    public function uniqueId(): string
    {
        return parent::uniqueId().':'.$this->sheet;
    }

    protected function run(ImportBatch $batch): void
    {
        (new BatchValidator)->normalizeSheet($batch, $this->sheet);
    }
}
