<?php

namespace App\Modules\ProgramImport\Parsing;

use Illuminate\Http\UploadedFile;

/** Extension + MIME + magic-byte checks for uploaded import files. */
class FileGuard
{
    /** @return array{ok: bool, ext: ?string, error: ?string} */
    public static function inspect(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, config('program_imports.extensions'), true)) {
            return ['ok' => false, 'ext' => null, 'error' => 'Only CSV and XLSX files are accepted.'];
        }
        if ($file->getSize() > config('program_imports.limits.max_file_kb') * 1024) {
            return ['ok' => false, 'ext' => null, 'error' => 'The file is too large.'];
        }
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 8);
        if ($ext === 'xlsx') {
            if (! str_starts_with($head, "PK\x03\x04")) {
                return ['ok' => false, 'ext' => null, 'error' => 'This is not a real XLSX file.'];
            }

            return ['ok' => true, 'ext' => 'xlsx', 'error' => null];
        }
        // CSV/TXT must be text: reject binary signatures (zip, pdf, exe, ole) and NUL bytes
        if (str_starts_with($head, "PK") || str_starts_with($head, '%PDF') || str_starts_with($head, 'MZ') || str_starts_with($head, "\xD0\xCF\x11\xE0")
            || str_contains((string) file_get_contents($file->getRealPath(), false, null, 0, 4096), "\0")) {
            return ['ok' => false, 'ext' => null, 'error' => 'This file is not plain text. Save it as CSV.'];
        }

        return ['ok' => true, 'ext' => 'csv', 'error' => null];
    }
}
