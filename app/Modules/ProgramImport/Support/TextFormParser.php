<?php

namespace App\Modules\ProgramImport\Support;

/** No-AI form extraction from pasted text: one question per line; a trailing * means required; "a / b / c" after a ? makes a choice. */
class TextFormParser
{
    public function parse(string $text): array
    {
        $questions = [];
        $title = null;
        foreach (preg_split('/\R/', $text) as $i => $line) {
            $line = trim(preg_replace('/^\s*(?:\d+[.)]|[-*•])\s*/u', '', $line));
            if ($line === '') {
                continue;
            }
            if ($title === null && ! str_contains($line, '?') && $i === 0) {
                $title = mb_substr($line, 0, 255);

                continue;
            }
            $required = str_ends_with($line, '*');
            $line = rtrim($line, "* \t");
            $options = [];
            if (preg_match('/^(.*\?)\s*(.+\/.+)$/u', $line, $m)) {
                $line = $m[1];
                $options = array_values(array_filter(array_map('trim', explode('/', $m[2]))));
            }
            $type = $options ? 'choice' : (preg_match('/\b(date|when)\b/i', $line) ? 'date' : (preg_match('/\b(describe|explain|tell us|why|how)\b/i', $line) ? 'paragraph' : 'text'));
            $questions[] = ['label' => mb_substr($line, 0, 500), 'help_text' => null, 'type' => $type, 'required' => $required, 'options' => $options, 'maps_to' => null, 'confidence' => 0.6, 'suggested' => true];
            if (count($questions) >= 200) {
                break;
            }
        }
        if (! $questions) {
            throw new FormFetchException('form_empty');
        }

        return ['title' => $title, 'description' => null, 'questions' => $questions];
    }
}
