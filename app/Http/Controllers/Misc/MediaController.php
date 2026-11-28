<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Public images (logos, profile pictures, business photos) served through the API, so the browser can read them across
 * origins (the /storage files are served by the web server without CORS headers). Used by the PDF exports to draw
 * pictures. Only image files already on the public disk are served; nothing outside it, nothing that is not an image.
 */
class MediaController extends Controller
{
    private const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];

    public function image(Request $request)
    {
        // Accepts "logos/a.png", "storage/logos/a.png" or "/storage/logos/a.png"
        $path = ltrim((string) $request->query('path', ''), '/');
        $path = preg_replace('#^storage/#', '', $path);

        abort_if($path === '' || str_contains($path, '..') || str_contains($path, "\0"), 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        $root = realpath($disk->path(''));
        $full = realpath($disk->path($path));
        abort_unless($root && $full && str_starts_with($full, $root.DIRECTORY_SEPARATOR) && is_file($full), 404);

        $mime = mime_content_type($full) ?: '';
        abort_unless(in_array($mime, self::IMAGE_TYPES, true), 404);

        return response()->file($full, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
