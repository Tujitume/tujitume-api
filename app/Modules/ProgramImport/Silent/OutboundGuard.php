<?php

namespace App\Modules\ProgramImport\Silent;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Called by the core outbound seams (ProgramNotificationService, EmailService, NotificationService, AiReviewDispatcher).
 * True means: do not send. Cheap and safe when the module is disabled or its tables do not exist.
 */
class OutboundGuard
{
    public const PLACEHOLDER_DOMAIN = '@placeholder.invalid';

    private static array $unclaimed = [];

    private static ?bool $tables = null;

    /** @param  object|int|string|null  $recipient  a user model, a user id or an email address */
    public static function shouldSuppress(mixed $recipient = null): bool
    {
        if (ImportContext::isSilent()) {
            return true;
        }
        if (! config('program_imports.enabled') || $recipient === null) {
            return false;
        }

        $id = null;
        $email = null;
        if (is_object($recipient)) {
            $id = $recipient->id ?? null;
            $email = $recipient->email ?? null;
        } elseif (is_int($recipient) || ctype_digit((string) $recipient)) {
            $id = (int) $recipient;
        } else {
            $email = (string) $recipient;
        }

        if ($email && str_ends_with(strtolower($email), self::PLACEHOLDER_DOMAIN)) {
            return true; // placeholders can never be contacted, even for invitations
        }
        if (ImportContext::isInviting()) {
            return false;
        }
        if (! $id && $email) {
            $id = DB::table('users')->where('email', $email)->value('id');
        }

        return $id ? self::isUnclaimed((int) $id) : false;
    }

    public static function isUnclaimed(int $userId): bool
    {
        if (self::$tables === null) {
            self::$tables = Schema::hasTable('program_import_users');
        }
        if (! self::$tables) {
            return false;
        }

        // Unclaimed = imported by a batch, never claimed, and still without a password (a password means the person took over the account)
        return self::$unclaimed[$userId] ??= DB::table('program_import_users')->join('users', 'users.id', '=', 'program_import_users.user_id')
            ->where('program_import_users.user_id', $userId)->whereNull('program_import_users.claimed_at')->whereNull('users.password')->exists();
    }

    /** Tests and long-running workers: forget what was cached. */
    public static function flush(): void
    {
        self::$unclaimed = [];
        self::$tables = null;
    }
}
