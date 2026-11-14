<?php

namespace App\Service\Misc;

use App\Models\Programs\ProgramApplication;

/**
 * Accounts made by the test-data generator (testuser1@example123.com ...). They have no real person behind them, so nothing is sent
 * to them and the AI never spends tokens on their applications.
 */
class AutomatedAccounts
{
    /**
     * TEMPORARY while the AI review is being tried out: generated applications ARE reviewed by the AI (each costs tokens).
     * To go back to never spending tokens on them, set this to false. Emails to them are never sent either way.
     */
    public static bool $reviewWithAi = true;

    /** SQL LIKE pattern of the generator's addresses (see TestApplicationGeneratorController). */
    public const EMAIL_LIKE = 'testuser%@example%.com';

    public static function isEmail(?string $email): bool
    {
        return $email !== null && (bool) preg_match('/^testuser\d*@example\d*\.com$/i', trim($email));
    }

    public static function forApplication(ProgramApplication $application): bool
    {
        $application->loadMissing('user:id,email');

        return self::isEmail($application->user?->email) || self::isEmail($application->contact_person_email);
    }
}
