<?php

namespace App\Support;

/**
 * The tier a match score (0-100) falls in, the same for programs and capital offers:
 *   ideal           80-100  "Ideal Match"     highlighted to the funder
 *   strong          60-79   "Strong Match"    worth a closer look
 *   needs_revision  under 60 "Needs Revision" the applicant is shown what to improve
 * The app shows the words; the API sends the key.
 */
final class MatchTier
{
    public const IDEAL = 80;

    public const STRONG = 60;

    public const KEYS = ['ideal', 'strong', 'needs_revision'];

    public const LABELS = ['ideal' => 'Ideal Match', 'strong' => 'Strong Match', 'needs_revision' => 'Needs Revision'];

    public static function of(mixed $score): ?string
    {
        if ($score === null || $score === '' || ! is_numeric($score)) return null;
        $score = (float) $score;

        return $score >= self::IDEAL ? 'ideal' : ($score >= self::STRONG ? 'strong' : 'needs_revision');
    }

    /** The tier worked out in SQL from a score column, for database lists (null without a score) */
    public static function sql(string $column): \Illuminate\Database\Query\Expression
    {
        return \Illuminate\Support\Facades\DB::raw("(CASE WHEN {$column} IS NULL THEN NULL WHEN {$column} >= ".self::IDEAL." THEN 'ideal' WHEN {$column} >= ".self::STRONG." THEN 'strong' ELSE 'needs_revision' END)");
    }

    public static function label(mixed $score): ?string
    {
        $tier = self::of($score);

        return $tier ? self::LABELS[$tier] : null;
    }
}
