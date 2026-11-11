<?php

namespace App\Service\Program;

/**
 * The round's rubric maths, shared by human scoring (ApplicationScoreController) and AI-only scoring.
 * Extracted without changing behaviour.
 */
class RubricCalculator
{
    public function calculate(array $criterionScores, array $scoringCriteria, string $rubricMode): float
    {
        return match ($rubricMode) {
            'weighted' => $this->weighted($criterionScores, $scoringCriteria),
            'pass_fail' => $this->passFail($criterionScores),
            'simple_total' => $this->simpleTotal($criterionScores),
            default => $this->simpleTotal($criterionScores),
        };
    }

    public function simpleTotal(array $criterionScores): float
    {
        // Just sum all raw scores
        return round(array_sum(array_column($criterionScores, 'score')), 2);
    }

    public function weighted(array $criterionScores, array $scoringCriteria): float
    {
        // Weight defined in scoring_criteria e.g. [{name, weight: 30, score_range: 100}]
        $criteriaMap = collect($scoringCriteria)->keyBy('name');
        $totalScore = 0;

        foreach ($criterionScores as $criterion) {
            $config = $criteriaMap->get($criterion['criterion']);
            $weight = $config['weight'] ?? 0;      // e.g. 30 means 30%
            $scoreRange = $this->maxScoreFor($config);

            // Normalize score to 0-100 then apply weight
            $normalized = ($criterion['score'] / $scoreRange) * 100;
            $totalScore += $normalized * ($weight / 100);
        }

        return round($totalScore, 2);
    }

    /**
     * Highest score a criterion allows. The round config saves it as "scoreRange"
     * in the form "0-10" or "1-5"; older data may carry a numeric "score_range".
     */
    public function maxScoreFor($config): float
    {
        $range = $config['score_range'] ?? $config['scoreRange'] ?? null;

        if (is_numeric($range)) {
            return max((float) $range, 1.0);
        }

        if (is_string($range) && preg_match_all('/\d+(?:\.\d+)?/', $range, $matches) && ! empty($matches[0])) {
            return max((float) end($matches[0]), 1.0);
        }

        return 100.0;
    }

    public function passFail(array $criterionScores): float
    {
        // Each criterion either passes (score > 0) or fails (score = 0)
        // Total = percentage of criteria passed
        $total = count($criterionScores);
        $passed = count(array_filter($criterionScores, fn ($c) => $c['score'] > 0));

        return round(($passed / $total) * 100, 2);
    }
}
