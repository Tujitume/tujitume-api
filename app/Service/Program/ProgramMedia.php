<?php

namespace App\Service\Program;

use App\Models\Programs\Program;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A program's cover image and intro video. The cover is kept on the s3 disk (the column holds its key, the model sends
 * cover_image_url); the video is a link the program page can play: YouTube, Vimeo, or a direct .mp4/.webm file.
 *
 * App\Service\Validation\UrlValidator is not used for the video: it calls the link over the network on every save
 * and accepts sites the program page cannot embed, while not accepting a direct video file.
 */
class ProgramMedia
{
    /** Validation rules for the cover upload (jpg, png or webp, up to 5 MB). */
    public const COVER_RULES = ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

    private const VIDEO_HOSTS = [
        'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
        'vimeo.com', 'www.vimeo.com', 'player.vimeo.com',
    ];

    /** Rules for intro_video_url, with "sometimes" or "nullable" added by the caller. */
    public static function videoRules(): array
    {
        return ['string', 'max:500', function (string $attribute, $value, \Closure $fail) {
            if (! self::isPlayableVideoUrl((string) $value)) {
                $fail('Use a YouTube or Vimeo link, or a direct link to an .mp4 or .webm video.');
            }
        }];
    }

    public static function isPlayableVideoUrl(string $url): bool
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (in_array($host, self::VIDEO_HOSTS, true)) {
            return true;
        }

        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return (bool) preg_match('#\.(mp4|webm)$#', $path);
    }

    /** Save a new cover for the program, removing the one it replaces. */
    public function storeCover(Program $program, UploadedFile $file): void
    {
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $path = Storage::disk('s3')->putFileAs(Program::COVER_DIRECTORY, $file, Str::uuid().'.'.$extension);
        if (! $path) {
            throw new \RuntimeException('Unable to store the program cover image.');
        }

        $previous = $program->cover_image;
        $program->forceFill(['cover_image' => $path])->save();
        $this->deleteFile($previous);
    }

    public function removeCover(Program $program): void
    {
        $previous = $program->cover_image;
        $program->forceFill(['cover_image' => null])->save();
        $this->deleteFile($previous);
    }

    private function deleteFile(?string $path): void
    {
        // A duplicated program shares its original's cover file, so a file still in use stays
        if (! $path || preg_match('#^https?://#i', $path) || Program::where('cover_image', $path)->exists()) {
            return;
        }
        try {
            Storage::disk('s3')->delete($path);
        } catch (\Throwable $e) {
            // The program no longer points at the file; a leftover object is harmless
        }
    }
}
