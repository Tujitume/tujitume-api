<?php

namespace App\Modules\ProgramImport\Jobs;

use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Pipeline\CommitRunner;

/** Writes one sheet's rows (per-row transactions). Restartable: rows already imported are never written again. */
class CommitChunkJob extends BatchJob
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
        (new CommitRunner)->runSheet($batch, $this->sheet);
    }
}
