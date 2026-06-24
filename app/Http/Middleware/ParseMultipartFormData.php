<?php

namespace App\Http\Middleware;

use Closure;
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
        if ($request->isMethod('PATCH') &&
            str_contains($request->header('Content-Type', ''), 'multipart/form-data')) {

            $boundary = substr(
                $request->header('Content-Type'),
                strpos($request->header('Content-Type'), 'boundary=') + 9
            );

            $blocks = explode("--$boundary", $request->getContent());
            $parsed = [];

            foreach ($blocks as $block) {
                if (empty(trim($block)) || $block === '--') continue;

                preg_match('/name="([^"]+)"/', $block, $nameMatch);
                if (empty($nameMatch[1])) continue;

                $name  = $nameMatch[1];
                $value = trim(substr($block, strpos($block, "\r\n\r\n") + 4));

                $parsed[$name] = $value;
            }

            $request->merge($parsed);
        }

        return $next($request);
    }
}
