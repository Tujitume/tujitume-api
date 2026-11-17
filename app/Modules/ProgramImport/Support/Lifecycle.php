<?php

namespace App\Modules\ProgramImport\Support;

/** Monotonic rule: an import may move a record forward in its lifecycle, never backward. */
class Lifecycle
{
    private const RANKS = [
        'program' => ['draft' => 0, 'published' => 1, 'open' => 2, 'closed' => 3, 'under_review' => 3, 'awarded' => 4, 'completed' => 5],
        'round' => ['draft' => 0, 'published' => 1, 'closed' => 2, 'in_review' => 3, 'finalized' => 4],
        'application' => ['draft' => 0, 'pending' => 1, 'under_review' => 2, 'approved' => 3, 'awarded' => 4],
        'funding' => ['not_started' => 0, 'in_progress' => 1, 'awaiting_applicant_revision' => 2, 'awaiting_owner_review' => 2, 'revision_requested' => 2, 'completed' => 3],
    ];

    public static function rank(string $kind, ?string $status): int
    {
        return self::RANKS[$kind][$status ?? ''] ?? -1;
    }

    /** True when moving from $from to $to goes forward or stays (unknown/terminal states never move). */
    public static function allows(string $kind, ?string $from, ?string $to): bool
    {
        if ($from === $to) {
            return true;
        }
        $a = self::rank($kind, $from);
        $b = self::rank($kind, $to);

        return $a >= 0 && $b > $a;
    }
}
