<?php

namespace App\Service\AiReview;

use App\Service\Program\RoundMatchScore;

/** Masks personal data before any text is sent to an AI provider. Counts are logged, never values. */
class Redactor
{
    public int $lastCount = 0;

    public function redact(string $text, ?string $applicantName = null): string
    {
        $count = 0;
        // Each rule hides the value but says what it was, so the model can judge it ("a valid company email") instead of
        // mistaking a bare "[email]" for a placeholder the applicant typed.
        $rules = [
            // social profile links first, so the generic URL rule does not hide what was removed
            '~https?://(?:www\.)?(?:linkedin|facebook|twitter|x|instagram|tiktok)\.com/[^\s)>\]]*~i' => fn () => '[social-profile]',
            '~[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}~i' => fn (string $m) => $this->describeEmail($m),
            // IBAN / bank-like: 2 letters + 2 digits + long alnum
            '~\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b~' => fn () => '[account-number]',
            // long digit runs (national id, passport, bank/account numbers, phone numbers): 8+ digits possibly separated
            '~(?<![\d])(?:\+\s?)?(?:\d[ \-]?){8,}(?![\d])~' => fn (string $m) => $this->describeNumber($m),
            // phone numbers: +254 712 345 678, 0712-345-678, (020) 123 4567
            '~(?<![\w])(?:\+?\d{1,3}[ \-.]?)?(?:\(\d{2,4}\)|\d{2,4})[ \-.]\d{3}[ \-.]?\d{3,4}(?![\w])~' => fn (string $m) => $this->describeNumber($m),
            // passport-like: 1-2 letters followed by 6-9 digits
            '~\b[A-Z]{1,2}\d{6,9}\b~' => fn () => '[id-number]',
        ];

        foreach ($rules as $pattern => $replacement) {
            $text = preg_replace_callback($pattern, function (array $match) use ($replacement, &$count) {
                $count++;

                return $replacement($match[0]);
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

    /**
     * For the answer to a "your name" question the name itself is hidden, but not by swapping its parts for "the applicant" (which reads as
     * placeholder text, so a real full name looked unfinished). The model is told what the answer is: how many words, and whether it looks like a name.
     */
    public function describeName(string $text): string
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $n = count($words);
        $this->lastCount++;
        if ($n >= 2 && ! preg_match('/\d/', $text)) return "[personal name given: {$n} words, plausible first and last name format]";
        if ($n === 1) return '[personal name given: 1 word only, a last name or first name may be missing]';

        return '[personal name: not in a plausible name format]';
    }

    /** The address is hidden; what the model needs to judge it is not: that it is well formed, and whether it is a company's own domain or a free mailbox. */
    private function describeEmail(string $email): string
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '[email address: not a valid format]';
        }
        $domain = strtolower(substr(strrchr($email, '@'), 1));

        return RoundMatchScore::isFreeEmailDomain($domain)
            ? "[email address: valid format, free webmail provider ({$domain}), not a company address]"
            : "[email address: valid format, company domain ({$domain})]";
    }

    /** The digits are hidden; their count and the country code they start with are kept. A leading 0 is read as a local number. */
    private function describeNumber(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        $length = strlen($digits);
        $withPlus = str_starts_with(ltrim($raw), '+');
        $code = null;
        foreach ([3, 2, 1] as $size) {
            if (in_array(substr($digits, 0, $size), RoundMatchScore::PHONE_CODES, true)) {
                $code = substr($digits, 0, $size);
                break;
            }
        }
        $facts = "{$length} digits";
        if (str_starts_with($digits, '0') && $length >= 9 && $length <= 11) {
            $facts .= ', local format with a leading 0';
        } elseif ($code !== null && ($withPlus || $length >= 10)) {
            $facts .= ", starts with country code +{$code}";
            if ($code === '254') {
                $facts .= RoundMatchScore::phoneBelongsTo($raw, 'ke') ? ', valid Kenyan mobile format' : ', not a valid Kenyan mobile format';
            }
        }

        return "[number: {$facts}]";
    }
}
