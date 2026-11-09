<?php

namespace App\Service\Program;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Reads the questions of a public Google Form and returns them as rows of the Excel question sheet
 * (the same cells the importer already understands), so the frontend can preview them like an Excel import.
 */
class GoogleFormReader
{
    private const MAX_ROWS = 1000;

    // Google Forms item type codes → label used by the question sheet
    private const TYPES = [
        0 => 'Short answer',
        1 => 'Long answer',
        2 => 'Multiple choice',
        3 => 'Dropdown',
        4 => 'Checkboxes',
        5 => 'Linear scale',
        7 => 'Matrix / grid',
        9 => 'Date',
        10 => 'Time',
        13 => 'File upload',
    ];

    public function read(string $url): array
    {
        $url = $this->normalizeUrl($url);

        $response = Http::timeout(15)->withHeaders(['Accept-Language' => 'en'])->get($url);
        if (! $response->successful()) {
            throw new InvalidArgumentException('form_unreachable');
        }

        if (! preg_match('/FB_PUBLIC_LOAD_DATA_\s*=\s*(\[.*?\]);\s*<\/script>/s', $response->body(), $m)) {
            throw new InvalidArgumentException('form_private');
        }
        $data = json_decode($m[1], true);
        $items = $data[1][1] ?? null;
        if (! is_array($items)) {
            throw new InvalidArgumentException('form_empty');
        }

        $rows = [];
        foreach ($items as $item) {
            $row = $this->row($item);
            if ($row) {
                $rows[] = ['rowNumber' => count($rows) + 1, 'cells' => $row];
            }
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }
        if (! $rows) {
            throw new InvalidArgumentException('form_empty');
        }

        return ['title' => $data[3] ?? ($data[1][8] ?? null), 'rows' => $rows];
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $isGoogle = $host === 'docs.google.com' || $host === 'forms.gle';
        if (! preg_match('#^https://#i', $url) || ! $isGoogle) {
            throw new InvalidArgumentException('form_url');
        }
        if ($host === 'forms.gle') {
            $resp = Http::timeout(10)->withOptions(['allow_redirects' => false])->get($url);
            $location = $resp->header('Location');
            if (! $location || strtolower((string) parse_url($location, PHP_URL_HOST)) !== 'docs.google.com') {
                throw new InvalidArgumentException('form_url');
            }
            $url = $location;
        }
        if (! preg_match('#^https://docs\.google\.com/forms/#i', $url)) {
            throw new InvalidArgumentException('form_url');
        }

        return preg_replace('#/(edit|viewform)?([?\#].*)?$#', '/viewform', $url);
    }

    private function row(array $item): ?array
    {
        $code = $item[3] ?? null;
        $title = trim((string) ($item[1] ?? ''));
        if (! isset(self::TYPES[$code]) || $title === '') {
            return null; // section headers, images, videos and untitled items are not questions
        }
        $entries = $item[4] ?? [];
        $first = $entries[0] ?? [];
        $required = ! empty($first[2]);
        $options = '';

        if (in_array($code, [2, 3, 4], true)) {
            $labels = [];
            foreach (($first[1] ?? []) as $option) {
                $label = trim(str_replace(';', ',', (string) ($option[0] ?? '')));
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
            if (count($labels) < 2) {
                $code = 0; // "Other"-only or empty choice list: ask it as a short answer
            } else {
                $options = implode('; ', $labels);
            }
        } elseif ($code === 7) {
            $rowsOut = [];
            foreach ($entries as $entry) {
                $name = trim(str_replace(';', ',', (string) ($entry[3][0] ?? '')));
                if ($name !== '') {
                    $rowsOut[] = "row: $name";
                }
            }
            $cols = [];
            foreach (($first[1] ?? []) as $option) {
                $name = trim(str_replace(';', ',', (string) ($option[0] ?? '')));
                if ($name !== '') {
                    $cols[] = "col: $name";
                }
            }
            if (count($rowsOut) < 1 || count($cols) < 2) {
                return null;
            }
            $options = implode('; ', array_merge($rowsOut, $cols));
        }

        $row = [
            'text' => $title,
            'type' => self::TYPES[$code],
            'required' => $required ? 'Yes' : 'No',
            'options' => $options,
            'score' => 'No',
            'import' => 'Yes',
        ];
        if ($code === 13) {
            $row['fileKinds'] = 'Document';
            $row['maxFiles'] = '1';
        }

        return $row;
    }
}
