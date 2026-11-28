<?php

namespace App\Http\Middleware;

use App\Models\Auth\UserSetting;
use App\Support\UserDateFormatter;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FormatDatesForUser
{
    /** Apply the authenticated user's date format and time zone (Settings) to JSON API responses. */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || ! $request->user()) {
            return $response;
        }

        $user = $request->user();
        $settings = $user->relationLoaded('settings')
            ? $user->settings
            : UserSetting::query()->where('user_id', $user->id)->first(['date_format', 'timezone']);

        $response->setData(UserDateFormatter::transform(
            $response->getData(true),
            $settings?->date_format ?? UserDateFormatter::DEFAULT_FORMAT,
            null,
            // Settings > Timezone when chosen, else the zone of the device making the request (sent by the app as X-Timezone)
            UserDateFormatter::zone($settings?->timezone ?: $request->header('X-Timezone')),
        ));

        return $response;
    }
}
