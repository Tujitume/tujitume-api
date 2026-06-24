<?php
namespace App\Support;

use Illuminate\Http\Request;
use Jenssegers\Agent\Agent;
use Illuminate\Support\Str;

class DeviceFingerprint
{
    public static function getOrCreateUuid(Request $request): string
    {
        // 1) prefer existing device_uuid cookie
        $cookie = $request->cookie('device_uuid');
        if ($cookie && Str::isUuid($cookie)) return $cookie;

        // 2) else create one now (store as httpOnly, long-lived after login)
        return (string) Str::orderedUuid();
    }

    public static function parseUA(Request $request): array
    {
        $agent = new Agent();
        $agent->setUserAgent($request->userAgent() ?? '');

        $platform = $agent->platform() ?: null;   // e.g., Windows, iOS
        $browser  = trim(($agent->browser() ?: '').' '.($agent->version($agent->browser()) ?: ''));

        return [
            'platform' => $platform,
            'browser'  => $browser,
        ];
    }
}
