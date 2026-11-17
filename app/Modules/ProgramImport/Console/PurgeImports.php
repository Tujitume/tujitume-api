<?php

namespace App\Modules\ProgramImport\Console;

use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\BatchStager;
use Illuminate\Console\Command;

/** Deletes raw files and staged rows after the retention period; keeps the batch summary and the ledger. */
class PurgeImports extends Command
{
    protected $signature = 'program-imports:purge {--days= : Override retention_days}';

    protected $description = 'Delete raw import files and staged rows older than the retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('program_imports.retention_days'));
        $n = 0;
        ImportBatch::where('updated_at', '<', now()->subDays($days))->whereIn('status', ['completed', 'completed_with_errors', 'failed', 'cancelled', 'rolled_back', 'has_errors', 'ready', 'uploaded', 'mapped', 'needs_mapping'])->each(function (ImportBatch $b) use (&$n) {
            \App\Modules\ProgramImport\Support\BatchFiles::delete($b);
            ImportRow::where('batch_id', $b->id)->delete();
            $b->update(['mapping' => $b->mapping ? ['purged' => true] : null]);
            $n++;
        });
        $this->info("Purged $n batch(es).");

        return self::SUCCESS;
    }
}
