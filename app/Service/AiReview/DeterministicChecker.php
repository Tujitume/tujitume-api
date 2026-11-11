<?php

namespace App\Service\AiReview;

use App\Models\Programs\Rounds\ProgramRound;

/** Free, explainable checks that run first and are always saved, even if the AI part fails. */
class DeterministicChecker
{
    /**
     * @param array $input from ReviewInputBuilder::build()
     * @return array{checks: array<int,array>, missing_items: array<int,string>}
     */
    public function check(ProgramRound $round, array $input): array
    {
        $checks = [];
        $missing = [];
        $config = $round->ai_review_config ?? [];

        // 1. Required answers
        foreach ($input['questions'] as $q) {
            if (! $q['required']) continue;
            if (($q['type'] ?? '') === 'file_upload') {
                $hasFile = collect($input['documents'])->contains(fn ($d) => ($d['question_id'] ?? null) === $q['id']);
                if (! $hasFile) {
                    $missing[] = 'Required upload not provided: '.$q['text'];
                    $checks[] = ['check' => 'required_answer', 'status' => 'fail', 'detail' => 'No file for: '.$q['text']];
                }
                continue;
            }
            $answer = trim((string) ($input['answers'][$q['id']]['text'] ?? ''));
            if ($answer === '') {
                $missing[] = 'Required question unanswered: '.$q['text'];
                $checks[] = ['check' => 'required_answer', 'status' => 'fail', 'detail' => 'Unanswered: '.$q['text']];
            }
        }

        // 2. Required documents: the round's required_documents list and the PO's document_rules
        $presentTypes = collect($input['documents'])->map(fn ($d) => $this->slug($d['document_type'] ?? $d['label'] ?? ''))->filter()->all();
        $required = collect($round->required_documents ?? [])
            ->filter(fn ($d) => ! empty($d['required']))
            ->map(fn ($d) => $this->slug($d['label'] ?? $d['type'] ?? ''))
            ->merge(collect($config['document_rules']['required_types'] ?? [])->map(fn ($t) => $this->slug($t)))
            ->filter()->unique();
        foreach ($required as $type) {
            $found = collect($presentTypes)->contains(fn ($p) => str_contains($p, $type) || str_contains($type, $p));
            if (! $found) {
                $missing[] = 'Required document missing: '.str_replace('_', ' ', $type);
                $checks[] = ['check' => 'required_document', 'status' => 'fail', 'detail' => 'Missing document: '.$type];
            }
        }

        // 3. Priority keywords with their locations
        $hits = [];
        foreach ($config['priority_keywords'] ?? [] as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword === '') continue;
            $where = [];
            foreach ($input['sources'] as $source) {
                if (stripos($source['text'], $keyword) !== false) {
                    $where[] = ['type' => $source['type'], 'id' => $source['id'], 'label' => $source['label']];
                }
            }
            $hits[] = ['keyword' => $keyword, 'found' => (bool) $where, 'locations' => $where];
        }
        if ($hits) {
            $found = count(array_filter($hits, fn ($h) => $h['found']));
            $checks[] = ['check' => 'priority_keywords', 'status' => $found ? 'info' : 'warn', 'detail' => "{$found} of ".count($hits).' priority keywords appear in the application.', 'keywords' => $hits];
        }

        // 4. Unreadable / skipped documents are reported, never silently dropped
        foreach ($input['extraction_issues'] as $issue) {
            $checks[] = ['check' => 'document_readable', 'status' => 'warn', 'detail' => $issue];
        }

        return ['checks' => $checks, 'missing_items' => array_values(array_unique($missing))];
    }

    private function slug(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($value)), '_');
    }
}
