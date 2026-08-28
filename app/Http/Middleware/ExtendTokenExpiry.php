<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ExtendTokenExpiry
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Extend token expiry on successful requests
        $token = $request->user()?->currentAccessToken();
        if ($token && $response->getStatusCode() < 400) {
            $token->forceFill([
                'last_used_at' => now(),
            ])->save();
        }

        return $response;
    }
}
