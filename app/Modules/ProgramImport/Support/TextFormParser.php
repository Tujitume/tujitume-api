<?php

namespace App\Modules\ProgramImport\Support;

/**
 * No-AI form extraction from pasted text. Understands two shapes:
 *  - the text of a Google Form page copied from the browser ("* Indicates required question", a "*" line under required questions,
 *    help text under the title, one option per line), and
 *  - a plain list: one question per line, a trailing * means required, "a / b / c" after a ? makes a choice, "- option" lines are options.
 * A line such as "Round 2" or "Round 2: Pitch" starts a new round; the questions below it belong to that round.
 * Types follow the round setup (short_answer, long_text, email, phone, url, video_link, multiple_choice, checkbox, date, file_upload, …).
 */
class TextFormParser
{
    private const NOISE = [
        '/^\*?\s*indicates required question$/i', '/switch account$/i', '/^a copy of your responses/i', '/^never submit passwords/i',
        '/^recaptcha$/i', '/^privacy$/i', '/^terms$/i', '/^this content is neither created nor endorsed/i', '/^does this form look suspicious/i',
        '/^google forms$/i', '/^submit$/i', '/^clear form$/i', '/^next$/i', '/^back$/i', '/^page \d+ of \d+$/i', '/^your answer$/i', '/^choose$/i',
        '/^report$/i', '/^sign in to google/i', '/^not shared$/i',
    ];

    public function parse(string $text): array
    {
        $lines = array_values(array_filter(array_map(fn ($l) => trim((string) $l), preg_split('/\R/u', $text)), fn ($l) => $l !== ''));
        $marker = null;
        foreach ($lines as $i => $l) {
            if (preg_match('/^\*?\s*indicates required question$/i', $l)) {
                $marker = $i;
                break;
            }
        }
        $spec = $marker !== null ? $this->googleCopy($lines, $marker) : $this->plain($lines);
        if (! $spec['questions']) {
            throw new FormFetchException('form_empty');
        }

        return $spec;
    }

    /** Text copied from a Google Form page. */
    private function googleCopy(array $lines, int $marker): array
    {
        $header = array_values(array_filter(array_slice($lines, 0, $marker), fn ($l) => ! $this->noise($l) && ! filter_var(strtok($l, ' '), FILTER_VALIDATE_EMAIL)));
        $title = $header ? mb_substr(array_shift($header), 0, 255) : null;
        $description = $header ? mb_substr(implode("\n", $header), 0, 500) : null;

        $body = [];
        foreach (array_slice($lines, $marker + 1) as $l) {
            if (preg_match('/^a copy of your responses|^never submit passwords|^recaptcha$|^submit$/i', $l)) {
                break;
            }
            if (! $this->noise($l)) {
                $body[] = $l;
            }
        }

        $blocks = [];
        $round = 1;
        $rounds = [1 => 'Application'];
        $n = count($body);
        for ($i = 0; $i < $n; $i++) {
            $line = $body[$i];
            if ($r = $this->roundLine($line)) {
                [$round, $name] = $r;
                $rounds[$round] = $name;

                continue;
            }
            $current = $blocks ? count($blocks) - 1 : null;
            $nextIsStar = ($body[$i + 1] ?? null) === '*';
            $startsBlock = $current === null || $nextIsStar || $this->looksLikeOptionalTitle($line, $body[$i + 1] ?? null, $blocks[$current] ?? null);
            if ($line === '*' && $current !== null) {
                $blocks[$current]['required'] = true;

                continue;
            }
            if ($startsBlock) {
                $blocks[] = ['title' => $line, 'required' => false, 'help' => [], 'options' => [], 'round' => $round];

                continue;
            }
            // Content under the current question: long lines are help, short lines are options
            if ($this->isHelp($line)) {
                $blocks[$current]['help'][] = $line;
            } else {
                $blocks[$current]['options'][] = $line;
            }
        }

        return ['title' => $title, 'description' => $description, 'questions' => array_map(fn ($b) => $this->question($b), array_slice($blocks, 0, 200)),
            'rounds' => $this->roundList($rounds)];
    }

    /** One question per line. */
    private function plain(array $lines): array
    {
        $questions = [];
        $title = null;
        $round = 1;
        $rounds = [1 => 'Application'];
        foreach ($lines as $i => $raw) {
            if ($r = $this->roundLine($raw)) {
                [$round, $name] = $r;
                $rounds[$round] = $name;

                continue;
            }
            if (preg_match('/^\s*(?:[-•◦▪o]|\[\s?\]|\(\s?\))\s+(.+)$/u', $raw, $m) && $questions) {
                // "- option" under a question
                $questions[count($questions) - 1]['options'][] = trim($m[1]);

                continue;
            }
            if (preg_match('/^>\s*(.+)$/u', $raw, $m) && $questions) {
                // "> help text" under a question
                $questions[count($questions) - 1]['help'][] = trim($m[1]);

                continue;
            }
            if ($raw === '*' && $questions) {
                $questions[count($questions) - 1]['required'] = true;

                continue;
            }
            $line = trim(preg_replace('/^\s*(?:\d+[.)]|[*•])\s*/u', '', $raw));
            if ($line === '' || $this->noise($line)) {
                continue;
            }
            if ($title === null && $i === 0 && ! str_contains($line, '?') && ! str_ends_with($line, '*') && count($lines) > 1) {
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
            $questions[] = ['title' => $line, 'required' => $required, 'help' => [], 'options' => $options, 'round' => $round];
            if (count($questions) >= 200) {
                break;
            }
        }

        return ['title' => $title, 'description' => null, 'questions' => array_map(fn ($b) => $this->question($b), $questions), 'rounds' => $this->roundList($rounds)];
    }

    private function question(array $b): array
    {
        $options = $b['options'];
        $allowOther = false;
        $options = array_values(array_filter($options, function ($o) use (&$allowOther) {
            if (preg_match('/^other:?$/i', $o)) {
                $allowOther = true;

                return false;
            }

            return true;
        }));
        $title = $b['title'];
        $help = implode("\n", $b['help']);
        $required = (bool) $b['required'];
        if (preg_match('/^\(optional\)\s*/i', $title)) {
            $required = false;
        }
        if (count($options) === 1 && $help === '') {
            // a single short line under a question is help, not a choice
            $help = $options[0];
            $options = [];
        }
        if ($allowOther) {
            $options[] = 'Other';
        }

        return [
            'label' => mb_substr($title, 0, 500), 'help_text' => $help !== '' ? mb_substr($help, 0, 1000) : null,
            'type' => self::inferType($title, $help, $options), 'required' => $required, 'options' => array_map(fn ($o) => mb_substr($o, 0, 200), $options),
            'round' => (int) $b['round'], 'maps_to' => null, 'confidence' => 0.8, 'suggested' => true,
        ];
    }

    /** The round-setup question type that best fits a title, its help and its options. */
    public static function inferType(string $title, string $help = '', array $options = []): string
    {
        $t = mb_strtolower($title);
        $all = $t.' '.mb_strtolower($help);
        if ($options) {
            if (preg_match('/check (?:all|multiple|any)|select (?:all|multiple|any)|multiple boxes|tick all|choose all/', $all)) {
                return 'checkbox';
            }
            if (count($options) === 2 && preg_match('/^(yes|no)$/i', $options[0]) && preg_match('/^(yes|no)$/i', $options[1])) {
                return 'yes_no';
            }

            return count($options) > 12 ? 'dropdown' : 'multiple_choice';
        }

        return match (true) {
            (bool) preg_match('/\bsignature\b|\bsign here\b/', $t) => 'signature',
            (bool) preg_match('/\b(upload|attach|attachment)\b|\bpitch deck\b|\b(pdf|document) of\b/', $t) => 'file_upload',
            (bool) preg_match('/\bvideo\b/', $t) => 'video_link',
            (bool) preg_match('/e-?mail/', $t) => 'email',
            (bool) preg_match('/\b(phone|mobile|telephone|whatsapp)\b/', $t) => 'phone',
            (bool) preg_match('/\b(website|url|link|linkedin)\b/', $t) => 'url',
            (bool) preg_match('/\b(date of|date|when did|deadline|founded on)\b/', $t) && ! preg_match('/\bupdate/', $t) => 'date',
            (bool) preg_match('/\bhow many\b|\bnumber of\b|\bheadcount\b/', $t) && mb_strlen($help) < 40 => 'number',
            (bool) preg_match('/\b(address)\b/', $t) => 'address',
            (bool) preg_match('/\b(location|where are you based|city|country)\b/', $t) && mb_strlen($help) < 40 => 'location',
            mb_strlen($help) >= 60 || (bool) preg_match('/\b(describe|explain|tell us|why|elaborate)\b|characters max/', $all) => 'long_text',
            default => 'short_answer',
        };
    }

    /** "Round 2", "Round 2: Pitch", "## Round 2 - Interview", "Stage 3" → [2, name] */
    private function roundLine(string $line): ?array
    {
        if (preg_match('/^#*\s*(?:round|stage|raundi)\s+(\d{1,2})\b\s*[:\-–—]?\s*(.*)$/iu', trim($line), $m) && mb_strlen($line) <= 80 && ! str_contains($line, '?')) {
            $n = max(1, min(10, (int) $m[1]));

            return [$n, trim($m[2]) !== '' ? mb_substr(trim($m[2]), 0, 120) : "Round $n"];
        }

        return null;
    }

    private function roundList(array $rounds): array
    {
        ksort($rounds);
        $out = [];
        foreach ($rounds as $n => $name) {
            $out[] = ['number' => (int) $n, 'name' => $name];
        }

        return $out;
    }

    private function noise(string $l): bool
    {
        foreach (self::NOISE as $re) {
            if (preg_match($re, $l)) {
                return true;
            }
        }

        return false;
    }

    private function isHelp(string $l): bool
    {
        return mb_strlen($l) > 60 || (bool) preg_match('/^\(|^[a-z]\)\s|characters max|\?\s.+/iu', $l);
    }

    /** A question without "*": a short line followed by its help text, or an "(Optional) …?" line, once the previous question has content. */
    private function looksLikeOptionalTitle(string $line, ?string $next, ?array $prev): bool
    {
        if ($line === '*' || mb_strlen($line) > 160 || preg_match('/^other:?$/i', $line)) {
            return false;
        }
        $prevHasContent = $prev && ($prev['help'] || $prev['options']);
        $prevEmpty = $prev && ! $prev['help'] && ! $prev['options'];
        if (preg_match('/^\(optional\)/i', $line)) {
            // "(Optional) Video of the founders…" right under a title is that title's help
            return ! $prevEmpty || preg_match('/[?:]$/', $line);
        }
        if (mb_strlen($line) <= 80 && $next !== null && $next !== '*' && $this->isHelp($next) && ! $this->isHelp($line)) {
            return $prevHasContent || $prevEmpty;
        }

        return false;
    }
}
