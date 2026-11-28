<?php

namespace App\Jobs\Concerns;

use App\Models\Programs\ProgramImport;

/**
 * An import email job's record of the people it did not email because their address cannot receive mail
 * (see ImportMail::problem): kept with the send's progress (skipped_ids, and each address with its reason), so they
 * are not tried again and again, and the page can say who was not emailed and why.
 */
trait RecordsImportSkips
{
    private function skip(ProgramImport $import, string $key, array $ids, string $address, string $reason): void
    {
        $import->refresh();
        $progress = $import->progress;
        $progress[$key]['skipped_ids'] = array_values(array_unique(array_merge($progress[$key]['skipped_ids'] ?? [], $ids)));
        $list = $progress[$key]['skipped'] ?? [];
        if (count($list) < 200 && ! collect($list)->contains('email', $address)) $list[] = ['email' => $address, 'reason' => $reason];
        $progress[$key]['skipped'] = $list;
        $import->forceFill(['progress' => $progress])->save();
    }
}
