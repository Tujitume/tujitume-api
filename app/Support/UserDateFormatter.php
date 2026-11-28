<?php

namespace App\Support;

use DateTimeInterface;

/**
 * Formats dates for an API response, for the person reading it, without changing persisted values:
 *   - calendar dates (2026-09-25) in their date format (Settings > Date format);
 *   - timestamps (stored in UTC) moved into their own time zone (Settings > Timezone) and written in their date format
 *     with the time, so 07:00 UTC reads 10:00 for someone in Nairobi and 02:00 for someone in New York;
 *   - an event's start_date / end_date, typed in the event's own `timezone`, keep their clock times as typed;
 *   - exact moments (MOMENT_FIELDS) stay ISO 8601 with their offset, for the client to show in any zone.
 */
class UserDateFormatter
{
    public const DEFAULT_FORMAT = 'DD/MM/YYYY';

    public const DEFAULT_TIMEZONE = 'Africa/Nairobi';

    /**
     * Exact moments the client counts down to or shows in the reader's zone. They stay ISO 8601 with their offset
     * (2026-12-01T05:00:00+00:00): rewritten in the user's date format they would lose the zone and read hours off.
     */
    public const MOMENT_FIELDS = ['application_opens_at', 'application_closes_at', 'opens_at', 'closes_at', 'starts_at', 'ends_at'];

    /** Clock times typed in a record's own `timezone` (an event's start and end): kept as typed, not converted */
    public const LOCAL_FIELDS = ['start_date', 'end_date'];

    /** A valid PHP time zone name, else the default */
    public static function zone(?string $zone): string
    {
        $zone = trim((string) $zone);

        return $zone !== '' && in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : self::DEFAULT_TIMEZONE;
    }

    /**
     * Converts the date-format tokens stored by the client to PHP date tokens.
     * Only date tokens are supported; time is deliberately not configurable here.
     */
    public static function phpFormat(?string $format): string
    {
        $format = trim((string) $format);

        if ($format === '') {
            $format = self::DEFAULT_FORMAT;
        }

        return strtr($format, [
            'YYYY' => 'Y', 'yyyy' => 'Y',
            'YY' => 'y', 'yy' => 'y',
            'MMMM' => 'F', 'MMM' => 'M',
            'MM' => 'm', 'DD' => 'd',
            'dd' => 'd', 'M' => 'n', 'D' => 'j', 'd' => 'j',
        ]);
    }

    public static function format(DateTimeInterface|string|null $date, ?string $format): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        try {
            $date = $date instanceof DateTimeInterface ? $date : \Carbon\Carbon::parse($date);

            return $date->format(self::phpFormat($format));
        } catch (\Throwable) {
            return is_string($date) ? $date : null;
        }
    }

    /**
     * Recursively formats date fields in an already-serialized JSON payload.
     * $timezone: the reader's zone (null keeps timestamps in UTC, as before). $ownZone: a LOCAL_FIELDS value of a
     * record that has its own `timezone`, whose clock time is already local to it.
     */
    public static function transform(mixed $value, ?string $format, ?string $field = null, ?string $timezone = null, bool $ownZone = false): mixed
    {
        if (is_array($value)) {
            $hasZone = isset($value['timezone']) && is_string($value['timezone']) && $value['timezone'] !== '';
            foreach ($value as $key => $item) {
                $local = $hasZone && in_array($key, self::LOCAL_FIELDS, true);
                $value[$key] = self::transform($item, $format, is_string($key) ? $key : null, $timezone, $local);
            }

            return $value;
        }

        if (! is_string($value) || ! self::isDateField($field) || in_array($field, self::MOMENT_FIELDS, true)) {
            return $value;
        }

        // Date-only values retain their date-only nature. Datetimes retain their time.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return self::format($value, $format);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}[T\s]/', $value)) {
            try {
                // Stored timestamps are UTC (an ISO value says its own offset); shown in the reader's zone
                $date = \Carbon\Carbon::parse($value, 'UTC');
                // A calendar date (a `date` column) serializes as midnight UTC: it is a day, not a moment, so it is never
                // moved into the reader's zone — otherwise anyone west of UTC would see the day before.
                $calendarDay = $date->format('H:i:s.u') === '00:00:00.000000';
                if ($timezone !== null && ! $ownZone && ! $calendarDay) {
                    $date = $date->setTimezone(self::zone($timezone));
                }

                return $date->format(self::phpFormat($format) . ' H:i:s');
            } catch (\Throwable) {
                return $value;
            }
        }

        return $value;
    }

    private static function isDateField(?string $field): bool
    {
        if (! $field) {
            return false;
        }

        return (bool) preg_match('/(?:^|_)(?:date|dob|deadline)(?:$|_)|(?:_at|_on)$/i', $field);
    }
}
