<?php

namespace App\Http\Controllers\Misc;

use Illuminate\Http\JsonResponse;

/**
 * Tells the frontend how to open the real-time (Pusher) connection, so the frontend needs no environment settings of its
 * own for it. Only values a browser must have anyway are returned: the public key, host, port, scheme and cluster.
 * The secret and the app id never leave the server.
 */
// A plain class on purpose: the shared base controller reads settings from the database, which this public lookup does not need
class RealtimeConfigController
{
    public function show(): JsonResponse
    {
        $pusher = config('broadcasting.connections.pusher');
        $client = $pusher['client'] ?? [];

        if (config('broadcasting.default') !== 'pusher' || empty($pusher['key']) || empty($client['host'])) {
            return response()->json(['enabled' => false]);
        }

        return response()->json([
            'enabled' => true,
            'key' => $pusher['key'],
            'cluster' => $pusher['options']['cluster'] ?? 'mt1',
            'host' => $client['host'],
            'port' => (int) ($client['port'] ?? 443),
            'scheme' => $client['scheme'] ?? 'https',
        ]);
    }
}
