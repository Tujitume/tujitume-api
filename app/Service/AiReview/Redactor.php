<?php

namespace App\Service\AiReview;

/** Masks personal data before any text is sent to an AI provider. Counts are logged, never values. */
class Redactor
{
    public int $lastCount = 0;

    public function redact(string $text, ?string $applicantName = null): string
    {
        $count = 0;
        $rules = [
            // social profile links first, so the generic URL rule does not hide what was removed
            '~https?://(?:www\.)?(?:linkedin|facebook|twitter|x|instagram|tiktok)\.com/[^\s)>\]]*~i' => '[social-profile]',
            '~[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}~i' => '[email]',
            // IBAN / bank-like: 2 letters + 2 digits + long alnum
            '~\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b~' => '[account-number]',
            // long digit runs (national id, passport, bank/account numbers): 8+ digits possibly separated
            '~(?<![\d])(?:\d[ \-]?){8,}(?![\d])~' => '[number]',
            // phone numbers: +254 712 345 678, 0712-345-678, (020) 123 4567
            '~(?<![\w])(?:\+?\d{1,3}[ \-.]?)?(?:\(\d{2,4}\)|\d{2,4})[ \-.]\d{3}[ \-.]?\d{3,4}(?![\w])~' => '[phone]',
            // passport-like: 1-2 letters followed by 6-9 digits
            '~\b[A-Z]{1,2}\d{6,9}\b~' => '[id-number]',
        ];

        foreach ($rules as $pattern => $replacement) {
            $text = preg_replace_callback($pattern, function () use ($replacement, &$count) {
                $count++;

                return $replacement;
            }, $text) ?? $text;
        }

        if ($applicantName && mb_strlen(trim($applicantName)) > 2) {
            foreach (array_filter(preg_split('/\s+/', trim($applicantName))) as $part) {
                if (mb_strlen($part) < 3) continue;
                $text = preg_replace_callback('/\b'.preg_quote($part, '/').'\b/iu', function () use (&$count) {
                    $count++;

                    return 'the applicant';
                }, $text) ?? $text;
            }
        }

        $this->lastCount += $count;

        return $text;
    }
}
