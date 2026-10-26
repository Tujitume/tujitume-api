<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ParseMultipartFormData
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle($request, Closure $next)
    {
        $temporaryFiles = [];

        if ($request->isMethod('PATCH') &&
            str_contains($request->header('Content-Type', ''), 'multipart/form-data')) {

            if (preg_match('/boundary=(?:"([^"]+)"|([^;]+))/i', $request->header('Content-Type'), $matches)) {
                $boundary = trim($matches[1] ?: $matches[2]);
                $input = [];
                $files = [];

                foreach (explode('--' . $boundary, $request->getContent()) as $part) {
                    $part = ltrim($part, "\r\n");
                    if ($part === '' || str_starts_with($part, '--')) {
                        continue;
                    }

                    $separator = strpos($part, "\r\n\r\n");
                    if ($separator === false) {
                        continue;
                    }

                    $headers = substr($part, 0, $separator);
                    $value = substr($part, $separator + 4);
                    if (str_ends_with($value, "\r\n")) {
                        $value = substr($value, 0, -2);
                    }

                    if (!preg_match('/Content-Disposition:\s*form-data;[^\r\n]*name="([^"]+)"/i', $headers, $nameMatch)) {
                        continue;
                    }

                    $name = $nameMatch[1];
                    if (preg_match('/filename="([^"]*)"/i', $headers, $fileMatch) && $fileMatch[1] !== '') {
                        $temporaryPath = tempnam(sys_get_temp_dir(), 'patch-upload-');
                        if ($temporaryPath === false || file_put_contents($temporaryPath, $value) === false) {
                            if ($temporaryPath !== false) @unlink($temporaryPath);
                            continue;
                        }

                        $temporaryFiles[] = $temporaryPath;
                        preg_match('/Content-Type:\s*([^\r\n]+)/i', $headers, $mimeMatch);
                        $files[$name] = new UploadedFile(
                            $temporaryPath,
                            rawurldecode($fileMatch[1]),
                            isset($mimeMatch[1]) ? trim($mimeMatch[1]) : null,
                            UPLOAD_ERR_OK,
                            true
                        );
                    } else {
                        // parse_str also supports bracketed multipart names such as answers[0].
                        parse_str(rawurlencode($name) . '=' . rawurlencode($value), $field);
                        $input = array_replace_recursive($input, $field);
                    }
                }

                $request->request->add($input);
                $request->files->add($files);
                // Keep a direct reference too, in case another middleware populated
                // Laravel's converted-file cache before this PATCH body was parsed.
                $request->attributes->set('parsed_multipart_files', $files);
            }
        }

        try {
            return $next($request);
        } finally {
            foreach ($temporaryFiles as $temporaryFile) {
                @unlink($temporaryFile);
            }
        }
    }
}
