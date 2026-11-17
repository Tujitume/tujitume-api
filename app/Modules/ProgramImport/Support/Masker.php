<?php

namespace App\Modules\ProgramImport\Support;

/** Privacy for AI calls: only shapes leave the platform (letters -> a/A, digits -> 9); emails and phones are fully masked. */
class Masker
{
    public static function shape(?string $v): string
    {
        $v = mb_substr((string) $v, 0, 40);
        if (preg_match('/@/', $v)) {
            return 'email@example';
        }
        if (preg_match('/^[+\d][\d\s().-]{6,}$/', $v)) {
            return '999999999';
        }

        return preg_replace(['/\p{Lu}/u', '/\p{Ll}/u', '/\d/'], ['A', 'a', '9'], $v);
    }

    /** @return list<string> up to $max distinct shapes (or real values when allowed AND not contact data) */
    public static function samples(array $values, int $max = 5, bool $real = false): array
    {
        $out = [];
        foreach ($values as $v) {
            if ($v === null || trim((string) $v) === '') {
                continue;
            }
            $s = (string) $v;
            $out[] = $real && ! preg_match('/@|^[+\d][\d\s().-]{6,}$/', $s) ? mb_substr($s, 0, 40) : self::shape($s);
            if (count(array_unique($out)) >= $max) {
                break;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, $max);
    }
}
