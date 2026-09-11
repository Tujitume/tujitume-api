<?php

namespace App\Http\Controllers;

class ConfigController extends Controller
{
    /**
     * Get public frontend configuration
     * Only return values that are intentionally safe to expose to browsers.
     */
    public function getPublicConfig()
    {
        $pusherKey = config('broadcasting.connections.pusher.key');
        $pusherOptions = config('broadcasting.connections.pusher.options', []);

        return response()->json([
            'pusher' => $pusherKey ? [
                'key' => $pusherKey,
                'cluster' => $pusherOptions['cluster'] ?? 'mt1',
                'host' => $pusherOptions['host'] ?? null,
                'port' => $pusherOptions['port'] ?? 443,
                'scheme' => $pusherOptions['scheme'] ?? 'https',
            ] : null,
            'stripe' => [
                'publishableKey' => config('services.stripe.publishable'),
            ],
            'app' => [
                'name' => config('app.name'),
                'url' => config('app.url'),
            ],
        ]);
    }
}
