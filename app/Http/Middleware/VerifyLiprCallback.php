<?php

namespace App\Http\Middleware;

use App\Service\LiprMpesa\LiprCallback;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** ISSUE-48: a LIPR callback must carry the signature the app put in the URL it gave LIPR (see LiprCallback). */
class VerifyLiprCallback
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('services.lipr.verify_callbacks', true)) {
            return $next($request);
        }

        if (! LiprCallback::valid('/'.$request->path(), $request->query('sig'))) {
            Log::warning('LIPR callback refused: missing or wrong signature', ['path' => $request->path(), 'ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid callback signature.'], 403);
        }

        return $next($request);
    }
}
