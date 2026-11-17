<?php

namespace App\Modules\ProgramImport\Support;

use App\Modules\ProgramImport\Models\ImportLink;
use Illuminate\Support\Facades\Schema;

/** Questions core code may ask about imported data (used by the scheduler guard). */
class ImportedRecords
{
    public static function isImportedRound(int $roundId): bool
    {
        return config('program_imports.enabled') && Schema::hasTable('program_import_links')
            && ImportLink::where('entity_type', 'round')->where('entity_id', $roundId)->exists();
    }
}
