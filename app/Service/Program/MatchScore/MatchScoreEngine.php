<?php

namespace App\Service\Program\MatchScore;

use App\Models\Programs\Program;
use App\Models\Programs\ProgramCustomField;

/**
 * Server-side match score from the program's own scoring fields (the single source of truth; client scores are ignored).
 * base = sum(weight_i * match_i) / sum(weight_i) * 100 over non-bonus scoring fields (unanswered = 0, still in the denominator);
 * rounded half up to 0..100; bonus fields add their points after normalization only when base >= match_score_limit; capped at 100.
 */
class MatchScoreEngine
{
    private const STRATEGIES = ['in_set' => InSetStrategy::class, 'range' => RangeStrategy::class, 'equals' => EqualsStrategy::class, 'provided' => ProvidedStrategy::class];

    /** @param  array<string,mixed>  $answers  answers keyed by field key */
    public function compute(Program $program, array $answers): array
    {
        $fields = $program->customFields()->where('audience', 'application')->where('is_archived', false)->where('is_scoring', true)->get()
            ->filter(fn (ProgramCustomField $f) => ($f->scoring['enabled'] ?? false) && isset(self::STRATEGIES[$f->scoring['match_type'] ?? '']));

        return $this->computeFor($fields->all(), $answers, $program->match_score_limit);
    }

    /** @param  list<ProgramCustomField>  $fields */
    public function computeFor(array $fields, array $answers, ?int $limit): array
    {
        $base = array_values(array_filter($fields, fn ($f) => ! $f->is_bonus));
        $bonus = array_values(array_filter($fields, fn ($f) => $f->is_bonus));
        $totalWeight = array_sum(array_map(fn ($f) => (float) $f->scoring['weight'], $base));
        if ($totalWeight <= 0) {
            return ['score' => null, 'breakdown' => [], 'bonus' => [], 'eligible_for_bonus' => false];
        }
        $breakdown = [];
        $sum = 0.0;
        foreach ($base as $f) {
            $match = $this->matchOf($f, $answers[$f->key] ?? null);
            $w = (float) $f->scoring['weight'];
            $earned = $w * $match / $totalWeight * 100;
            $sum += $earned;
            $breakdown[] = ['field_id' => $f->id, 'key' => $f->key, 'label' => $f->label, 'match_type' => $f->scoring['match_type'], 'weight' => $w,
                'normalized_weight' => round($w / $totalWeight * 100, 2), 'match' => round($match, 4), 'earned_points' => round($earned, 2),
                'answer' => $answers[$f->key] ?? null, 'target' => $f->scoring['target'] ?? null];
        }
        $score = (int) floor($sum + 0.5);
        $eligible = $limit === null || $score >= $limit;
        $bonusRows = [];
        $points = 0.0;
        foreach ($bonus as $f) {
            $match = $this->matchOf($f, $answers[$f->key] ?? null);
            $earned = $eligible ? (float) $f->scoring['weight'] * $match : 0.0;
            $points += $earned;
            $bonusRows[] = ['field_id' => $f->id, 'key' => $f->key, 'label' => $f->label, 'match_type' => $f->scoring['match_type'], 'weight' => (float) $f->scoring['weight'], 'match' => round($match, 4), 'earned_points' => round($earned, 2)];
        }

        return ['score' => max(0, min(100, (int) floor($score + $points + 0.5))), 'breakdown' => $breakdown, 'bonus' => $bonusRows, 'eligible_for_bonus' => $eligible];
    }

    private function matchOf(ProgramCustomField $f, mixed $answer): float
    {
        $class = self::STRATEGIES[$f->scoring['match_type']];

        return max(0.0, min(1.0, (new $class)->match($f, $answer, $f->scoring)));
    }

    /** What an applicant may see: per field matched / partial / not matched, never weights or targets. */
    public function applicantView(array $computed): array
    {
        return array_map(fn ($b) => ['key' => $b['key'], 'label' => $b['label'], 'status' => $b['match'] >= 1 ? 'matched' : ($b['match'] > 0 ? 'partial' : 'not matched')], $computed['breakdown']);
    }
}
