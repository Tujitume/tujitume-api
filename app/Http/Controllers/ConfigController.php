<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ConfigController extends Controller
{
    /**
     * Get public frontend configuration
     * Only returns PUBLIC keys that are safe to expose
     */
    public function getPublicConfig()
    {
        return response()->json([
            'pusher' => [
                'key' => config('broadcasting.connections.pusher.key'),
                'cluster' => config('broadcasting.connections.pusher.options.cluster'),
                'host' => config('broadcasting.connections.pusher.options.host'),
                'port' => config('broadcasting.connections.pusher.options.port', 443),
                'scheme' => config('broadcasting.connections.pusher.options.scheme', 'https'),
            ],
            'stripe' => [
                'publishableKey' => config('services.stripe.key'), // Only publishable key
            ],
            'app' => [
                'name' => config('app.name'),
                'url' => config('app.url'),
            ],
        ]);
    }
}
