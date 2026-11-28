<?php

namespace App\Service\Capital;

use App\Service\File\FileUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Capital uploads (offer brief, pitch deck / video / business plan, milestone documents, terms agreements, investment
 * guidelines) are stored on the s3 disk like the rest of the app. Older rows hold a local path under public/ ("files/...");
 * url() opens both.
 */
class CapitalFiles
{
    public static function store(?UploadedFile $file, string $folder): ?string
    {
        return $file ? (new FileUploadService())->saveFile($file, $folder) : null;
    }

    /** A link the app can open: a short-lived signed s3 link, or the public link for an older local file */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        if (file_exists(public_path($path))) {
            return url($path);
        }
        try {
            return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(45), ['ResponseContentDisposition' => 'inline']);
        } catch (\Throwable) {
            try {
                return Storage::disk('s3')->url($path);
            } catch (\Throwable) {
                return null;
            }
        }
    }

    public static function delete(?string $path): void
    {
        if (! $path) {
            return;
        }
        try {
            if (file_exists(public_path($path))) {
                @unlink(public_path($path));
            } else {
                Storage::disk('s3')->delete($path);
            }
        } catch (\Throwable) {
            // a missing file is not an error
        }
    }
}
