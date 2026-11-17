<?php

namespace App\Modules\ProgramImport\Jobs;

use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Silent\ImportContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Base for the import phase jobs: dedicated queue, unique per batch and phase, silent, retry-safe (each phase restarts cleanly). */
abstract class BatchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public int $batchId)
    {
        $this->onQueue(config('program_imports.queue'));
    }

    public function uniqueId(): string
    {
        return static::class.':'.$this->batchId;
    }

    abstract protected function run(ImportBatch $batch): void;

    public function handle(): void
    {
        $batch = ImportBatch::find($this->batchId);
        if ($batch && ! in_array($batch->status, ['cancelled', 'rolled_back'], true)) {
            ImportContext::silently(fn () => $this->run($batch));
        }
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        ImportBatch::where('id', $this->batchId)->update(['status' => 'failed', 'error_summary' => json_encode(['message' => 'The import stopped unexpectedly. You can try again.'])]);
    }
}
