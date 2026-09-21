<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ServicesMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        //$uri = Route::current()?->uri();
        $method = $request->method();;
        $isServiceProvider = Auth::user()?->user_type_id === 3;

        $user = Auth::user();
        if (! $user) {
            return $next($request);
        }


        // KYC verification required
        $user->load('kycVerification');

        if ($isServiceProvider && $user->kycVerification?->status !== 'verified') {
            return response()->json([
                'message' => 'KYC verification is required to access service provider functionality.',
                'status' => 403,
            ], 403);
        }

        // return original request
        return $next($request);
    }
}
