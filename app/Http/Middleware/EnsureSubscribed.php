<?php

namespace App\Http\Middleware;

use App\Service\Subscription\SubscriptionAccess;
use Closure;
use Illuminate\Http\Request;

/** 'subscribed': a program organisation without an active plan cannot do this (402, code subscription_required). */
class EnsureSubscribed
{
    public function handle(Request $request, Closure $next)
    {
        if (SubscriptionAccess::blocked($request->user())) {
            return response()->json(['message' => SubscriptionAccess::MESSAGE, 'code' => SubscriptionAccess::CODE], 402);
        }

        return $next($request);
    }
}
