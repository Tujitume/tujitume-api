<?php

namespace App\Service\AiReview;

use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;
use App\Service\Program\RubricCalculator;
use App\Service\Program\ScoreAverager;

/** AI-only scoring. Refuses to score when the evidence is weak; never touches status or decisions. */
class AiScoringService
{
    public function __construct(private RubricCalculator $rubric, private ScoreAverager $averager)
    {
    }

    /**
     * Map the model's suggestions onto the round's criteria (exact names, clamped to each range).
     * @return array{ok:bool, reason:?string, scores:array, total:?float}
     */
    public function map(ProgramRound $round, array $suggested): array
    {
        $criteria = collect($round->scoring_criteria ?? []);
        if ($criteria->isEmpty()) return ['ok' => false, 'reason' => 'The round has no scoring criteria.', 'scores' => [], 'total' => null];

        $byName = collect($suggested)->keyBy(fn ($s) => trim($s['criterion']));
        $scores = [];
        $missing = [];
        foreach ($criteria as $c) {
            $name = $c['name'] ?? '';
            $s = $byName->get($name);
            if (! $s) { $missing[] = $name; continue; }
            $max = $this->rubric->maxScoreFor($c);
            $scores[] = ['criterion' => $name, 'score' => round(max(0, min($max, (float) $s['score'])), 2), 'max' => $max, 'rationale' => $s['rationale'] ?? '', 'comment' => $s['rationale'] ?? null];
        }
        if ($missing) return ['ok' => false, 'reason' => 'The AI did not score: '.implode(', ', $missing), 'scores' => $scores, 'total' => null];

        $total = $this->rubric->calculate(array_map(fn ($s) => ['criterion' => $s['criterion'], 'score' => $s['score']], $scores), $round->scoring_criteria, $round->rubric_mode ?? 'simple_total');

        return ['ok' => true, 'reason' => null, 'scores' => $scores, 'total' => $total];
    }

    /** @return array{scored:bool, reason:?string} */
    public function scoreApplication(ApplicationAiReview $review, ProgramApplication $application, ProgramRound $round): array
    {
        $quality = $review->quality ?? [];
        $min = (float) config('ai_review.ai_only.min_verified_ratio');

        if (($quality['verified_quote_ratio'] ?? 0) < $min) {
            return ['scored' => false, 'reason' => 'Too few of the AI\'s quotes could be verified against the application ('.round(($quality['verified_quote_ratio'] ?? 0) * 100).'%).'];
        }
        if (($quality['schema_repairs'] ?? 0) > 1) {
            return ['scored' => false, 'reason' => 'The AI report needed repeated corrections.'];
        }

        $mapped = $this->map($round, $review->suggested_scores ?? []);
        if (! $mapped['ok']) return ['scored' => false, 'reason' => $mapped['reason']];

        ApplicationScore::updateOrCreate(
            ['application_id' => $application->id, 'round_id' => $round->id, 'reviewer_id' => AiSystemUser::get()->id],
            [
                'source' => 'ai', 'ai_review_id' => $review->id,
                'criterion_scores' => array_map(fn ($s) => ['criterion' => $s['criterion'], 'score' => $s['score'], 'comment' => $s['comment']], $mapped['scores']),
                'total_score' => $mapped['total'], 'overall_comment' => $review->summary, 'scored_at' => now(),
            ]
        );

        // The same updates a human score makes: average, eligibility, round_status = scored
        $this->averager->apply($application, $round);

        return ['scored' => true, 'reason' => null];
    }
}
