<?php

namespace App\Modules\ProgramImport\Mapping;

use App\Modules\ProgramImport\Registry\ImportTypeRegistry;

/**
 * No-AI mapper: normalized header -> registry field using key, label and synonyms, then Levenshtein.
 * This is the complete fallback; the module works end to end with AI off.
 */
class HeuristicMapper
{
    public const MIN_CONFIDENCE = 0.72;

    public static function norm(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($s)));
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string,string>  $questions  question_ref => question text (header -> question:<ref> matches)
     * @return array{columns: array, ignored: list<string>}
     */
    public function map(string $type, array $headers, array $questions = [], array $customFields = []): array
    {
        $fields = ImportTypeRegistry::fields($type);
        $candidates = [];
        foreach ($fields as $key => $f) {
            foreach (array_merge([$key, $f['label']], $f['synonyms']) as $name) {
                $candidates[self::norm($name)][] = $key;
            }
        }
        $columns = [];
        $ignored = [];
        $used = [];
        foreach ($headers as $header) {
            $n = self::norm($header);
            $best = null;
            if (isset($candidates[$n])) {
                $best = [$candidates[$n][0], 1.0];
            } else {
                foreach ($candidates as $name => $keys) {
                    $len = max(strlen($n), strlen($name));
                    if ($len === 0) {
                        continue;
                    }
                    $score = 1 - levenshtein($n, $name) / $len;
                    if (! $best || $score > $best[1]) {
                        $best = [$keys[0], $score];
                    }
                }
            }
            if ($best && $best[1] >= self::MIN_CONFIDENCE && ! isset($used[$best[0]])) {
                $columns[$header] = ['target' => $best[0], 'confidence' => round($best[1], 2), 'origin' => 'heuristic'];
                $used[$best[0]] = true;

                continue;
            }
            foreach ($questions as $ref => $text) {
                $qn = self::norm($text);
                if ($qn !== '' && ($qn === $n || (strlen($n) > 12 && (str_contains($qn, $n) || str_contains($n, $qn))))) {
                    $columns[$header] = ['target' => 'question:'.$ref, 'confidence' => 0.9, 'origin' => 'heuristic'];

                    continue 2;
                }
            }
            foreach ($customFields as $ref => $label) {
                $ln = self::norm($label);
                if ($ln !== '' && ($ln === $n || (strlen($n) > 6 && (str_contains($ln, $n) || str_contains($n, $ln))))) {
                    $columns[$header] = ['target' => 'custom:'.$ref, 'confidence' => 0.9, 'origin' => 'heuristic'];

                    continue 2;
                }
            }
            $ignored[] = $header;
        }

        return ['columns' => $columns, 'ignored' => $ignored];
    }
}
