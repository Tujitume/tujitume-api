<?php

namespace App\Service\AiReview;

/**
 * Things a reviewer would notice at a glance about an uploaded document, found in code so the model does not have to dig for them: the document says it
 * is fictional or a sample, it has a placeholder email, it still has template blanks. They are observations about the document, never a verdict.
 * (The same checks the browser's "Let Amari score" makes, so both read a document the same way.)
 */
class DocumentFlags
{
    private const FLAGS = [
        ['/fictional|fictitious|for demonstration|demonstration purposes|sample (?:cv|resume|document)|lorem ipsum|dummy (?:text|data)|specimen|invented (?:roles|qualifications)/i', 'the document itself says it is fictional, a sample or a demonstration'],
        ['/@(?:example|test|domain|email|company|mail)\.(?:com|org|net)\b|\bexample\.com\b/i', 'it contains a placeholder email address (example.com or similar)'],
        ['/\[(?:your|insert|company|name|date)[^\]]*\]|\bxxx+\b|\bTBD\b/i', 'it contains unfilled template placeholders'],
    ];

    /** @return string[] */
    public static function of(string $text): array
    {
        $found = [];
        foreach (self::FLAGS as [$pattern, $note]) {
            if (preg_match($pattern, $text)) $found[] = $note;
        }

        return $found;
    }
}
