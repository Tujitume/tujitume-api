<?php

namespace App\Modules\ProgramImport\Normalizers;

use Carbon\Carbon;

/**
 * Cell normalizers. Each returns ['value' => mixed, 'error' => ?string, 'warning' => ?string].
 * Cells are untrusted: text is stripped of HTML and neutralized against spreadsheet formulas.
 */
class Normalizer
{
    private static function ok(mixed $v, ?string $warning = null): array
    {
        return ['value' => $v, 'error' => null, 'warning' => $warning];
    }

    private static function bad(string $msg): array
    {
        return ['value' => null, 'error' => $msg, 'warning' => null];
    }

    public static function blank(mixed $v): bool
    {
        return $v === null || (is_string($v) && trim($v) === '');
    }

    /** Strip HTML, collapse control characters, trim, and neutralize leading = + - @ (formula injection). */
    public static function text(mixed $v): array
    {
        if (self::blank($v)) {
            return self::ok(null);
        }
        $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', strip_tags((string) $v)));

        return self::ok(self::neutralize($s));
    }

    public static function neutralize(string $s): string
    {
        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$s : $s;
    }

    public static function money(mixed $v): array
    {
        if (self::blank($v)) {
            return self::ok(null);
        }
        if (is_int($v) || is_float($v)) {
            return $v < 0 ? self::bad('Amount cannot be negative') : self::ok(round((float) $v, 2));
        }
        $s = trim((string) $v);
        // Accept an optional 3-letter currency code or symbol in front, thousands separators, up to 2 decimals. "50k" is an error.
        if (! preg_match('/^(?:[A-Za-z]{3}|[$€£])?\s*(\d{1,3}(?:,\d{3})+|\d+)(\.\d{1,2})?$/', $s, $m)) {
            return self::bad("\"$s\" is not a valid amount (use digits, e.g. 50000 or KES 50,000)");
        }

        return self::ok((float) (str_replace(',', '', $m[1]).($m[2] ?? '')));
    }

    public static function decimal(mixed $v): array
    {
        if (self::blank($v)) {
            return self::ok(null);
        }
        $s = str_replace(',', '', trim((string) $v));

        return is_numeric($s) ? self::ok((float) $s) : self::bad("\"$v\" is not a number");
    }

    public static function int(mixed $v): array
    {
        $d = self::decimal($v);
        if ($d['error'] || $d['value'] === null) {
            return $d;
        }

        return floor($d['value']) == $d['value'] ? self::ok((int) $d['value']) : self::bad("\"$v\" must be a whole number");
    }

    public static function bool(mixed $v): array
    {
        if (self::blank($v)) {
            return self::ok(null);
        }
        $s = strtolower(trim((string) $v));
        if (in_array($s, ['1', 'true', 'yes', 'y'], true)) {
            return self::ok(true);
        }
        if (in_array($s, ['0', 'false', 'no', 'n'], true)) {
            return self::ok(false);
        }

        return self::bad("\"$v\" is not yes/no");
    }

    /** $format: PHP date format (e.g. d/m/Y). Without one, only ISO (Y-m-d) is accepted unambiguously; d/m vs m/d is warned. */
    public static function date(mixed $v, ?string $format = null): array
    {
        if (self::blank($v)) {
            return self::ok(null);
        }
        if ($v instanceof \DateTimeInterface) {
            return self::ok(Carbon::instance($v)->toDateString());
        }
        $s = trim((string) $v);
        if (is_numeric($s) && (float) $s > 20000 && (float) $s < 80000) { // Excel serial date
            return self::ok(Carbon::create(1899, 12, 30)->addDays((int) $s)->toDateString());
        }
        try {
            if ($format) {
                $d = Carbon::createFromFormat('!'.$format, $s);
                if ($d === false || $d->format($format) !== $s) {
                    return self::bad("\"$s\" does not match the date format $format");
                }

                return self::ok($d->toDateString());
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
                $d = Carbon::createFromFormat('!Y-m-d', $s);
                if ($d === false || $d->format('Y-m-d') !== $s) {
                    return self::bad("\"$s\" is not a real date");
                }

                return self::ok($d->toDateString());
            }
            if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $s, $m)) {
                if ((int) $m[1] > 12) {
                    return self::ok(Carbon::createFromFormat('!j/n/Y', "$m[1]/$m[2]/$m[3]")->toDateString());
                }
                if ((int) $m[2] > 12) {
                    return self::ok(Carbon::createFromFormat('!n/j/Y', "$m[1]/$m[2]/$m[3]")->toDateString());
                }

                return self::bad("\"$s\" is ambiguous (day/month or month/day). Choose a date format for this import.");
            }
        } catch (\Throwable) {
        }

        return self::bad("\"$s\" is not a recognised date (use YYYY-MM-DD)");
    }

    public static function email(mixed $v): array
    {
        if (self::blank($v)) {
            return self::ok(null);
        }
        $s = strtolower(trim((string) $v));

        return filter_var($s, FILTER_VALIDATE_EMAIL) ? self::ok($s) : self::bad("\"$v\" is not a valid email");
    }

    /** E.164 when a country calling code is known ($defaultCode e.g. "254"); otherwise digits are kept and a warning added. */
    public static function phone(mixed $v, ?string $defaultCode = null): array
    {
        if (self::blank($v)) {
            return self::ok(null);
        }
        $s = preg_replace('/[\s().-]/', '', trim((string) $v));
        if (preg_match('/^\+\d{8,15}$/', $s)) {
            return self::ok($s);
        }
        if (! preg_match('/^\d{7,15}$/', $s)) {
            return self::bad("\"$v\" is not a valid phone number");
        }
        if ($defaultCode) {
            return self::ok('+'.$defaultCode.ltrim($s, '0'));
        }

        return self::ok($s, 'No country code: stored as given');
    }

    public static function url(mixed $v): array
    {
        if (self::blank($v)) {
            return self::ok(null);
        }
        $s = trim((string) $v);

        return preg_match('#^https?://#i', $s) && filter_var($s, FILTER_VALIDATE_URL) ? self::ok($s) : self::bad("\"$v\" is not a valid http(s) link");
    }

    /** An http(s) link, or a plain file name that must exist in the attachments ZIP (no paths). */
    public static function linkOrFile(mixed $v): array
    {
        if (self::blank($v)) {
            return self::ok(null);
        }
        $s = trim((string) $v);
        if (preg_match('#^https?://#i', $s)) {
            return self::url($s);
        }
        if (preg_match('#[/\\\x00]|\.\.#', $s) || strlen($s) > 200 || ! preg_match('/\.[A-Za-z0-9]{2,5}$/', $s)) {
            return self::bad("\"$s\" must be a link or a file name from the attachments ZIP");
        }

        return self::ok($s);
    }

    public static function list(mixed $v): array
    {
        if (self::blank($v)) {
            return self::ok([]);
        }
        $sep = preg_match('/[;|\n]/', (string) $v) ? '/[;|\n]/' : '/,/';
        $items = array_values(array_filter(array_map(fn ($x) => self::text($x)['value'], preg_split($sep, (string) $v))));

        return self::ok($items);
    }

    /** Enum: case-insensitive, with a value map (e.g. "Won" => "awarded") applied first. */
    public static function enum(mixed $v, array $allowed, array $valueMap = []): array
    {
        if (self::blank($v)) {
            return self::ok(null);
        }
        $s = trim((string) $v);
        foreach ($valueMap as $from => $to) {
            if (strcasecmp((string) $from, $s) === 0) {
                $s = (string) $to;
                break;
            }
        }
        foreach ($allowed as $a) {
            if (strcasecmp($a, $s) === 0 || strcasecmp($a, str_replace([' ', '-'], '_', $s)) === 0) {
                return self::ok($a);
            }
        }

        return self::bad("\"$v\" is not one of: ".implode(', ', $allowed));
    }
}
