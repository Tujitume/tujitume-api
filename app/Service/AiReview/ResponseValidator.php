<?php

namespace App\Service\AiReview;

use App\Models\Programs\Ai\ApplicationAiFinding;

/** Strict validation of model output. Returns errors, never throws. */
class ResponseValidator
{
    /** @return array{errors:array<int,string>, data:array} */
    public function validate(?array $json, array $criteriaNames, bool $requireScores): array
    {
        $errors = [];
        if (! is_array($json)) return ['errors' => ['reply is not a JSON object'], 'data' => []];

        $summary = $json['summary'] ?? null;
        if (! is_string($summary) || trim($summary) === '') $errors[] = 'summary must be a non-empty string';

        $findings = [];
        // Findings are no longer asked for (the reviewer reads the score and the reason for each criterion), so none is fine; any sent are still checked
        if (isset($json['findings']) && ! is_array($json['findings'])) {
            $errors[] = 'findings must be an array';
        } elseif (isset($json['findings'])) {
            foreach ($json['findings'] as $i => $f) {
                if (! is_array($f)) { $errors[] = "findings[{$i}] must be an object"; continue; }
                $e = [];
                if (! in_array($f['dimension'] ?? null, [...ApplicationAiFinding::DIMENSIONS, 'data_quality'], true)) $e[] = "findings[{$i}].dimension is not allowed";
                if (! in_array($f['polarity'] ?? null, ApplicationAiFinding::POLARITIES, true)) $e[] = "findings[{$i}].polarity is not allowed";
                if (! is_string($f['title'] ?? null) || trim($f['title']) === '') $e[] = "findings[{$i}].title is required";
                if (! is_string($f['body'] ?? null) || trim($f['body']) === '') $e[] = "findings[{$i}].body is required";
                $type = $f['source_type'] ?? null;
                if ($type !== null && ! in_array($type, ['answer', 'document', 'program', 'custom_field'], true)) $e[] = "findings[{$i}].source_type is not allowed";
                if (in_array($type, ['answer', 'document'], true)) {
                    if (! isset($f['source_id'])) $e[] = "findings[{$i}].source_id is required when a source is cited";
                    if (! is_string($f['quote'] ?? null) || trim($f['quote']) === '') $e[] = "findings[{$i}].quote is required when a source is cited";
                }
                if (isset($f['confidence']) && ! in_array($f['confidence'], ['low', 'medium', 'high'], true)) $e[] = "findings[{$i}].confidence is not allowed";
                if ($e) { $errors = array_merge($errors, $e); continue; }

                $findings[] = [
                    'dimension' => $f['dimension'], 'polarity' => $f['polarity'],
                    'title' => mb_substr(trim($f['title']), 0, 200), 'body' => trim($f['body']),
                    'confidence' => $f['confidence'] ?? null, 'source_type' => $type,
                    'source_id' => isset($f['source_id']) ? (int) preg_replace('/\D/', '', (string) $f['source_id']) : null,
                    'page' => isset($f['page']) && is_numeric($f['page']) ? (int) $f['page'] : null,
                    'quote' => isset($f['quote']) ? mb_substr(trim((string) $f['quote']), 0, 300) : null,
                ];
            }
        }

        $scores = [];
        if ($requireScores) {
            if (! isset($json['scores']) || ! is_array($json['scores'])) {
                $errors[] = 'scores must be an array';
            } else {
                foreach ($json['scores'] as $i => $s) {
                    if (! is_array($s) || ! is_string($s['criterion'] ?? null) || ! is_numeric($s['score'] ?? null)) {
                        $errors[] = "scores[{$i}] needs criterion and numeric score";
                        continue;
                    }
                    $scores[] = ['criterion' => trim($s['criterion']), 'score' => (float) $s['score'], 'max' => isset($s['max']) && is_numeric($s['max']) ? (float) $s['max'] : null, 'rationale' => trim((string) ($s['rationale'] ?? ''))];
                }
            }
        } elseif (isset($json['scores']) && is_array($json['scores'])) {
            foreach ($json['scores'] as $s) {
                if (is_array($s) && is_string($s['criterion'] ?? null) && is_numeric($s['score'] ?? null)) {
                    $scores[] = ['criterion' => trim($s['criterion']), 'score' => (float) $s['score'], 'max' => isset($s['max']) && is_numeric($s['max']) ? (float) $s['max'] : null, 'rationale' => trim((string) ($s['rationale'] ?? ''))];
                }
            }
        }

        $missing = array_values(array_filter(array_map(fn ($m) => is_string($m) ? mb_substr(trim($m), 0, 300) : null, (array) ($json['missing_items'] ?? []))));

        return ['errors' => $errors, 'data' => ['summary' => is_string($summary) ? trim($summary) : '', 'findings' => $findings, 'scores' => $scores, 'missing_items' => $missing]];
    }
}
