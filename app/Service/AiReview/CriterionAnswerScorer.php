<?php

namespace App\Service\AiReview;

/** Applies explicit question answer rules after AI scoring, before scores are saved. */
class CriterionAnswerScorer
{
    /**
     * Keep the first rubric criterion AI-scored. For later criteria tied to a question,
     * explicit match answers take precedence; otherwise any non-empty answer earns full marks.
     */
    public function apply(array $scores, array $criteria, array $input): array
    {
        $questions = collect($input['questions'] ?? []);
        $byCriterion = collect($criteria)->keyBy('name');
        $out = [];

        foreach ($scores as $score) {
            $criterion = $byCriterion->get($score['criterion'] ?? '');
            $criterionIndex = collect($criteria)->search(fn ($item) => ($item['name'] ?? null) === ($score['criterion'] ?? null));
            if (! is_array($criterion)) {
                $out[] = $score;
                continue;
            }

            $question = $this->questionForCriterion($criterion, $questions);
            if (! $question) {
                $out[] = $score;
                continue;
            }

            // File requirements are objective and apply even to the first criterion.
            // Other first-criterion scores remain AI-generated as requested.
            if ($criterionIndex === 0 && ($question['type'] ?? null) !== 'file_upload') {
                $out[] = $score;
                continue;
            }

            $max = $this->maxScore($criterion);
            if (($question['type'] ?? null) === 'file_upload') {
                $present = $this->fileMatches($question, $input['documents'] ?? []);
                $reason = $present
                    ? 'Uploaded file matches the file type or extension required by the question.'
                    : 'No uploaded file matches the file type or extension required by the question.';
            } else {
                $answer = trim((string) ($input['answers'][$question['id']]['text'] ?? ''));
                if ($answer === '') {
                    $present = false;
                    $reason = 'No answer was provided for the linked question.';
                } elseif ($this->hasMatchValue($question['match_answers'] ?? null)) {
                    $present = $this->matches($answer, $question['match_answers']);
                    $reason = $present
                        ? 'Answer matches the question’s configured match_answers value.'
                        : 'Answer does not match the question’s configured match_answers value.';
                } else {
                    $present = true;
                    $reason = 'Answer is present; the linked question has no match_answers value to compare against.';
                }
            }

            $score['score'] = $present ? $max : 0.0;
            $score['max'] = $max;
            $score['rationale'] = $reason;
            $score['comment'] = $reason;
            $out[] = $score;
        }

        return $out;
    }

    private function questionForCriterion(array $criterion, $questions): ?array
    {
        $description = (string) ($criterion['description'] ?? '');
        if (preg_match('/\bQ\s*(\d+)\b/i', $description, $match)) {
            $question = $questions->firstWhere('display_order', (int) $match[1]);
            if ($question) return $question;
        }

        foreach ($questions as $question) {
            if (str_contains(mb_strtolower($description), mb_strtolower((string) $question['text']))) return $question;
        }

        return null;
    }

    private function maxScore(array $criterion): float
    {
        $range = $criterion['score_range'] ?? $criterion['scoreRange'] ?? null;
        if (is_numeric($range)) return max(1.0, (float) $range);
        if (is_string($range) && preg_match_all('/\d+(?:\.\d+)?/', $range, $matches) && $matches[0]) {
            return max(1.0, (float) end($matches[0]));
        }
        return 100.0;
    }

    private function hasMatchValue(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) return false;
        if (is_array($value)) return collect($value)->contains(fn ($item) => $this->hasMatchValue($item));
        return true;
    }

    private function matches(string $answer, mixed $match): bool
    {
        if (is_array($match)) {
            if (isset($match['keywords'])) {
                foreach ((array) $match['keywords'] as $keyword) {
                    $keyword = trim((string) $keyword);
                    if ($keyword !== '' && str_contains(mb_strtolower($answer), mb_strtolower($keyword))) return true;
                }
                return false;
            }
            foreach ($match as $expected) {
                if ($this->matches($answer, $expected)) return true;
            }
            return false;
        }
        if (! is_scalar($match)) return false;
        $expected = trim((string) $match);
        return $expected !== '' && (mb_strtolower(trim($answer)) === mb_strtolower($expected)
            || str_contains(mb_strtolower($answer), mb_strtolower($expected)));
    }

    private function fileMatches(array $question, array $documents): bool
    {
        $documents = collect($documents)->filter(fn ($doc) => (int) ($doc['question_id'] ?? 0) === (int) $question['id']);
        if ($documents->isEmpty()) return false;

        $requiredExtensions = collect($question['options'] ?? [])
            ->filter(fn ($option) => is_string($option) && str_starts_with(mb_strtolower($option), 'type:'))
            ->map(fn ($option) => ltrim(mb_strtolower(substr($option, 5)), '.'))
            ->filter()->values();
        if ($requiredExtensions->isEmpty()) return true;

        return $documents->contains(function ($document) use ($requiredExtensions) {
            $extension = mb_strtolower(pathinfo((string) ($document['path'] ?? ''), PATHINFO_EXTENSION));
            return $extension !== '' && $requiredExtensions->contains($extension);
        });
    }
}
