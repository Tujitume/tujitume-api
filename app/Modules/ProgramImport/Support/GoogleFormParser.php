<?php

namespace App\Modules\ProgramImport\Support;

/**
 * Best-effort parse of the (undocumented) FB_PUBLIC_LOAD_DATA_ blob. Type codes follow the platform's existing reader
 * (0 short, 1 long, 2 choice, 3 dropdown, 4 checkboxes, 5 scale, 9 date, 10 time, 13 file upload); verified against fixtures.
 */
class GoogleFormParser
{
    private const TYPES = [0 => 'short_answer', 1 => 'long_text', 2 => 'multiple_choice', 3 => 'dropdown', 4 => 'checkbox', 5 => 'linear_scale', 7 => 'matrix', 9 => 'date', 10 => 'time', 13 => 'file_upload', 18 => 'rating'];

    /** @return array{title: ?string, description: ?string, questions: list<array>} */
    public function parse(string $html): array
    {
        if (! preg_match('/FB_PUBLIC_LOAD_DATA_\s*=\s*(\[.*?\]);\s*<\/script>/s', $html, $m)) {
            throw new FormFetchException('form_private');
        }
        $data = json_decode($m[1], true);
        $items = $data[1][1] ?? null;
        if (! is_array($items)) {
            throw new FormFetchException('form_empty');
        }
        $questions = [];
        $round = 1;
        $rounds = [['number' => 1, 'name' => 'Application']];
        foreach ($items as $item) {
            $code = $item[3] ?? null;
            $title = trim((string) ($item[1] ?? ''));
            // A section titled "Round 2" / "Stage 2: Interview" starts a new round
            if ($code === 8 && preg_match('/^(?:round|stage|raundi)\s+(\d{1,2})\b\s*[:\-–—]?\s*(.*)$/iu', $title, $rm)) {
                $round = max(1, min(10, (int) $rm[1]));
                $rounds[] = ['number' => $round, 'name' => trim($rm[2]) !== '' ? mb_substr(trim($rm[2]), 0, 120) : "Round $round"];

                continue;
            }
            if (! isset(self::TYPES[$code]) || $title === '' || count($questions) >= 200) {
                continue;
            }
            $first = ($item[4] ?? [])[0] ?? [];
            $options = [];
            if (in_array($code, [2, 3, 4], true)) {
                foreach ($first[1] ?? [] as $o) {
                    $label = trim((string) ($o[0] ?? ''));
                    $label !== '' && $options[] = $label;
                }
                if (count($options) < 1) {
                    $code = 0;
                    $options = [];
                }
            }
            $questions[] = [
                'label' => mb_substr($title, 0, 500), 'help_text' => trim((string) ($item[2] ?? '')) ?: null, 'type' => self::TYPES[$code], 'round' => $round,
                'required' => ! empty($first[2]), 'options' => $options, 'maps_to' => null, 'confidence' => 1.0, 'suggested' => true,
            ];
        }
        if (! $questions) {
            throw new FormFetchException('form_empty');
        }

        return ['title' => $data[3] ?? ($data[1][8] ?? null), 'description' => $data[1][0] ?? null, 'questions' => $questions, 'rounds' => $rounds];
    }
}
