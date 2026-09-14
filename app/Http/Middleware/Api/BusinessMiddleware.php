<?php

namespace App\Http\Middleware\Api;

use Route;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class BusinessMiddleware
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
        $isBusinessUser = Auth::user()?->user_type_id === 1;

        $user = Auth::user();
        if (! $user) {
            return $next($request);
        }


        // KYC verification required
        $user->load('kycVerification');

        if ($isBusinessUser && $user->kycVerification?->status !== 'verified') {
            return response()->json([
                'message' => 'KYC verification is required to access business functionality.',
                'status' => 403,
            ], 403);
        }

        // return original request
        return $next($request);
    }
}
