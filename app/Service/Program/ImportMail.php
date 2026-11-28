<?php

namespace App\Service\Program;

use Illuminate\Support\Facades\Cache;

/**
 * The guard every email an import sends goes through (applicants, Business Coaches, suppliers, site visit officers,
 * "import finished"), so a large send does not get the sending account flagged:
 *  - an address that cannot receive mail is not sent to (not an address, a test or reserved domain, or a domain with
 *    no mail server), so nothing bounces; the job records it as not sent, with the reason;
 *  - emails go out at a steady pace (mail.import.per_minute), never in a burst.
 */
class ImportMail
{
    private const RESERVED = ['example.com', 'example.net', 'example.org', 'localhost'];
    private const RESERVED_ENDINGS = ['.test', '.example', '.invalid', '.localhost', '.local'];

    private static float $lastSentAt = 0.0;

    /** @return string|null why this address cannot be emailed ('invalid' | 'no_mail_domain'), or null when it can */
    public static function problem(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) return 'invalid';
        if (! config('mail.import.check_domains')) return null;

        $domain = substr(strrchr($email, '@'), 1);
        if (in_array($domain, self::RESERVED, true)) return 'no_mail_domain';
        foreach (self::RESERVED_ENDINGS as $end) {
            if (str_ends_with($domain, $end)) return 'no_mail_domain';
        }

        // A domain that can receive mail has a mail server (MX), or at least an address (A); asked once a day per domain
        $receives = Cache::remember("import-mail-domain:{$domain}", now()->addDay(), fn () => checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A'));

        return $receives ? null : 'no_mail_domain';
    }

    /** Waits so emails leave at the configured pace (0 or less: no wait). Call after each person is emailed. */
    public static function pace(): void
    {
        $perMinute = (int) config('mail.import.per_minute', 0);
        if ($perMinute <= 0) return;
        $wait = self::$lastSentAt + 60 / $perMinute - microtime(true);
        if ($wait > 0) usleep((int) round($wait * 1_000_000));
        self::$lastSentAt = microtime(true);
    }
}
