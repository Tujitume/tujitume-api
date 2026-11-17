<?php

namespace App\Modules\ProgramImport\Support;

use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Pipeline\BatchStager;

/** Every raw file a batch stored: the data file and the optional attachments ZIP. */
class BatchFiles
{
    public static function delete(ImportBatch $batch): void
    {
        foreach ([$batch->file_path, $batch->options['attachments_path'] ?? null] as $path) {
            $path && BatchStager::disk()->delete($path);
        }
        $options = $batch->options ?? [];
        unset($options['attachments_path']);
        $batch->forceFill(['file_path' => null, 'options' => $options])->save();
    }
}
