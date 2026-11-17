<?php

namespace App\Modules\ProgramImport\Silent;

/**
 * Process-wide "silent mode" switch. While an import job runs, nothing leaves the platform:
 * no mail, notification, broadcast or AI-review dispatch (see OutboundGuard and the core seams).
 */
class ImportContext
{
    private static int $silentDepth = 0;

    private static int $inviteDepth = 0;

    public static function isSilent(): bool
    {
        return self::$silentDepth > 0;
    }

    /** Run $fn in silent mode (re-entrant, always restored). */
    public static function silently(callable $fn): mixed
    {
        self::$silentDepth++;
        try {
            return $fn();
        } finally {
            self::$silentDepth--;
        }
    }

    /** Deliberate claim invitations may reach unclaimed imported users (never placeholders). */
    public static function inviting(callable $fn): mixed
    {
        self::$inviteDepth++;
        try {
            return $fn();
        } finally {
            self::$inviteDepth--;
        }
    }

    public static function isInviting(): bool
    {
        return self::$inviteDepth > 0;
    }
}
