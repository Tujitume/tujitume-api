<?php

namespace App\Modules\ProgramImport\Support;

use App\Modules\ProgramImport\Models\ImportBatch;

/** Who did what to a batch (kept in batch stats, newest last, capped). The raw file and rows are purged later; this stays. */
class Audit
{
    public static function log(ImportBatch $batch, ?int $userId, string $action, array $meta = []): void
    {
        $stats = $batch->stats ?? [];
        $audit = $stats['audit'] ?? [];
        $audit[] = ['at' => now()->toIso8601String(), 'user_id' => $userId, 'action' => $action] + ($meta ? ['meta' => $meta] : []);
        $stats['audit'] = array_slice($audit, -100);
        $batch->stats = $stats;
        $batch->save();
    }
}
