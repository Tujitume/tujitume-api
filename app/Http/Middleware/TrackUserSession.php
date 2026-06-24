<?php
namespace App\Http\Middleware;

use App\Models\Auth\UserDevice;
use App\Models\Auth\UserSession;
use App\Support\DeviceFingerprint;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TrackUserSession
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (Auth::check()) {
            $user = $request->user();
            $sessionId = $request->session()->getId();

            $deviceUuid = DeviceFingerprint::getOrCreateUuid($request);
            $device = UserDevice::firstOrCreate(
                ['device_uuid' => $deviceUuid, 'user_id' => $user->id],
                array_merge(DeviceFingerprint::parseUA($request), [
                    'ip' => $request->ip(),
                    'name' => null,
                    'is_verified' => false,
                ])
            );

            $device->update(['last_seen_at' => now(), 'ip' => $request->ip()]);

            UserSession::updateOrCreate(
                ['id' => $sessionId],
                [
                    'user_id' => $user->id,
                    'user_device_id' => $device->id,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'last_activity' => now()->timestamp,
                ]
            );
        }

        return $response;
    }
}

