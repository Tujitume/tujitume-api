<?php

namespace App\Support;

use App\Models\Auth\User;
use App\Models\Auth\UserSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Exact opening and closing moments for programs and rounds.
 *
 * Moments are stored in UTC. A program records the zone its owner set them in (programs.timezone): its calendar dates
 * are that zone's dates, and a date sent without a time is read in that zone, opening at 00:00:00 and closing at
 * 23:59:59. The zone people type and read times in is their own Settings > Timezone, Africa/Nairobi when unset.
 */
final class ProgramSchedule
{
    public const DEFAULT_TIMEZONE = 'Africa/Nairobi';

    /** The API writes moments like this: ISO 8601 with the offset (always +00:00). */
    public const JSON_FORMAT = 'Y-m-d\TH:i:sP';

    /** A known zone name, or the default one. */
    public static function timezone(?string $timezone): string
    {
        $timezone = trim((string) $timezone);

        return $timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)
            ? $timezone
            : self::DEFAULT_TIMEZONE;
    }

    /** The zone of the device making this request (the app sends it as X-Timezone), else the default */
    public static function deviceZone(): string
    {
        return self::timezone(app()->bound('request') ? request()->header('X-Timezone') : null);
    }

    /** The zone a person types and reads times in. Never chosen: their device's zone (X-Timezone), else the default. */
    public static function forUser(?User $user): string
    {
        if (! $user) {
            return self::DEFAULT_TIMEZONE;
        }

        $timezone = $user->relationLoaded('settings')
            ? $user->settings?->timezone
            : UserSetting::query()->where('user_id', $user->id)->value('timezone');

        // Never chosen: the zone of the device making this request (the app sends it as X-Timezone)
        if (! $timezone && app()->bound('request')) {
            $timezone = request()->header('X-Timezone');
        }

        return self::timezone($timezone);
    }

    /**
     * A moment sent by a form, in UTC. "2026-12-01T08:00:00+03:00" keeps its own offset; "2026-12-01 08:00" is read in
     * $timezone. Anything that is not a date gives null (validation reports it).
     */
    public static function parse(mixed $value, string $timezone): ?Carbon
    {
        if ($value instanceof CarbonInterface || $value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->utc();
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value), $timezone)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The zone a request's times are typed in: the `timezone` it sends when that is a known zone, otherwise the
     * signed-in person's own setting.
     */
    public static function forRequest(\Illuminate\Http\Request $request): string
    {
        $sent = trim((string) $request->input('timezone'));

        return $sent !== '' && self::timezone($sent) === $sent ? $sent : self::forUser($request->user());
    }

    /**
     * Reads the moments a form sent ($pairs: momentField => dateField) and writes each one's calendar date into the
     * request, so the existing date rules check the same days. Returns momentField => moment (UTC) for those sent.
     */
    public static function readMoments(\Illuminate\Http\Request $request, array $pairs, string $timezone): array
    {
        $moments = [];
        foreach ($pairs as $momentField => $dateField) {
            $at = $request->filled($momentField) ? self::parse($request->input($momentField), $timezone) : null;
            if (! $at) {
                continue;
            }
            $moments[$momentField] = $at;
            $request->merge([$dateField => self::localDate($at, $timezone)]);
        }

        return $moments;
    }

    /** The first (opening) or last (closing) second of a calendar date in $timezone, in UTC. */
    public static function dayBound(mixed $date, string $timezone, bool $closing): ?Carbon
    {
        $day = self::calendarDate($date);
        if (! $day) {
            return null;
        }
        $local = Carbon::createFromFormat('Y-m-d', $day, $timezone);

        return ($closing ? $local->endOfDay()->startOfSecond() : $local->startOfDay())->utc();
    }

    /** The calendar date of a moment in $timezone ("2026-12-01"). */
    public static function localDate(?CarbonInterface $moment, string $timezone): ?string
    {
        return $moment ? $moment->copy()->setTimezone($timezone)->toDateString() : null;
    }

    /** "1 Dec 2026, 23:59 EAT": a moment written for a message, in $timezone. */
    public static function describe(?CarbonInterface $moment, string $timezone): ?string
    {
        return $moment ? $moment->copy()->setTimezone($timezone)->format('j M Y, H:i T') : null;
    }

    /** "2026-12-01" from a Carbon, a date string, or a datetime string. */
    private static function calendarDate(mixed $date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }
        if (! is_string($date) || ! preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($date), $match)) {
            return null;
        }

        return $match[1];
    }
}
