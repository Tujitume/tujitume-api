<?php
namespace App\Service\Validation;

class SpamWordChecker
{
    /**
     * Check multiple text fields for profanity.
     * @return bool
     */

    protected array $badWords;

    public function __construct()
    {
        $json = file_get_contents(resource_path('profanity/en.json'));
        $words = json_decode($json, true);

        $this->badWords = [];

        // Split pipe-separated phrases into individual words/phrases
        foreach ($words as $entry) {
            $matches = explode('|', $entry['match']);
            foreach ($matches as $word) {
                $word = trim(strtolower($word));
                if ($word) {
                    $this->badWords[] = $word;
                }
            }
        }
    }

    public function check($text1, $text2 = null, $text3 = null, $text4 = null, $text5 = null): bool
    {
        $texts = [$text1, $text2, $text3, $text4, $text5];

        foreach ($texts as $text) {
            if (!$text) continue;
            $text = strtolower(strip_tags($text)); // normalize

            foreach ($this->badWords as $word) {
                // Match whole word or phrase
                if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $text)) {
                    return false; // profanity detected
                }

                if (preg_match('/\b' . preg_quote('shit', '/') . '\b/', $text) ||
                    preg_match('/\b' . preg_quote('damn', '/') . '\b/', $text)) {
                    return false; // profanity detected
                }
            }
        }

        return true; // clean
    }
}
