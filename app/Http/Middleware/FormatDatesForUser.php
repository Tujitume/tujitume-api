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
    /** Apply the authenticated user's calendar-date preference to JSON API responses. */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || ! $request->user()) {
            return $response;
        }

        $user = $request->user();
        $dateFormat = $user->relationLoaded('settings')
            ? $user->settings?->date_format
            : UserSetting::query()->where('user_id', $user->id)->value('date_format');

        $response->setData(UserDateFormatter::transform(
            $response->getData(true),
            $dateFormat ?? UserDateFormatter::DEFAULT_FORMAT,
        ));

        return $response;
    }
}
