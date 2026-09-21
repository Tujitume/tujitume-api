<?php

namespace App\Support;

use DateTimeInterface;

/** Formats calendar dates for an API response without changing persisted values. */
class UserDateFormatter
{
    public const DEFAULT_FORMAT = 'DD/MM/YYYY';

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

    /** Recursively formats calendar-date fields in an already-serialized JSON payload. */
    public static function transform(mixed $value, ?string $format, ?string $field = null): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::transform($item, $format, is_string($key) ? $key : null);
            }

            return $value;
        }

        if (! is_string($value) || ! self::isDateField($field)) {
            return $value;
        }

        // Date-only values retain their date-only nature. Datetimes retain their time.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return self::format($value, $format);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}[T\s]/', $value)) {
            try {
                $date = \Carbon\Carbon::parse($value);

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
