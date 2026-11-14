<?php

namespace App\Service\Misc;

use App\Models\Programs\ProgramApplication;

/**
 * Accounts made by the test-data generator (testuser1@example123.com ...). They have no real person behind them, so nothing is sent
 * to them and the AI never spends tokens on their applications.
 */
class AutomatedAccounts
{
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
