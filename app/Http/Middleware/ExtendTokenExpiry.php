<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ExtendTokenExpiry
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Mark the token as used on successful requests: the idle timeout (config sanctum.idle_timeout) counts from here
        // Only a stored API token has a last_used_at; a session (cookie) sign-in carries a TransientToken with nothing to save
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken && $response->getStatusCode() < 400) {
            $token->forceFill([
                'last_used_at' => now(),
            ])->save();
        }

        return $response;
    }
}
