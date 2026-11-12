<?php

namespace App\Service\AiReview;

use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;
use App\Service\Program\RubricCalculator;
use App\Service\Program\ScoreAverager;

/** AI-only scoring. Quote verification is reported for audit but never gates scoring. */
class AiScoringService
{
    public function __construct(private RubricCalculator $rubric, private ScoreAverager $averager)
    {
    }

    /**
     * Map the model's suggestions onto the round's criteria (exact names, clamped to each range).
     * @return array{ok:bool, reason:?string, scores:array, total:?float}
     */
    public function map(ProgramRound $round, array $suggested, bool $allowIncomplete = false): array
    {
        $criteria = collect($round->scoring_criteria ?? []);
        // Published rounds should have a rubric. If one is missing, still create an
        // auditable zero score instead of leaving an AI-only application unscored.
        if ($criteria->isEmpty()) {
            return $allowIncomplete
                ? ['ok' => true, 'reason' => null, 'scores' => [], 'total' => 0.0]
                : ['ok' => false, 'reason' => 'The round has no scoring criteria.', 'scores' => [], 'total' => null];
        }

        $byName = collect($suggested)->keyBy(fn ($s) => trim($s['criterion']));
        $scores = [];
        $missing = [];
        foreach ($criteria as $c) {
            $name = $c['name'] ?? '';
            $s = $byName->get($name);
            if (! $s && ! $allowIncomplete) { $missing[] = $name; continue; }
            $max = $this->rubric->maxScoreFor($c);
            $score = $s ? round(max(0, min($max, (float) $s['score'])), 2) : 0.0;
            $rationale = $s['rationale'] ?? 'No usable AI score was returned for this criterion; recorded as 0 so the owner can review it.';
            $scores[] = ['criterion' => $name, 'score' => $score, 'max' => $max, 'rationale' => $rationale, 'comment' => $rationale];
        }
        if ($missing) return ['ok' => false, 'reason' => 'The AI did not score: '.implode(', ', $missing), 'scores' => $scores, 'total' => null];

        $total = $this->rubric->calculate(array_map(fn ($s) => ['criterion' => $s['criterion'], 'score' => $s['score']], $scores), $round->scoring_criteria, $round->rubric_mode ?? 'simple_total');

        return ['ok' => true, 'reason' => null, 'scores' => $scores, 'total' => $total];
    }

    /** @return array{scored:bool, reason:?string} */
    public function scoreApplication(ApplicationAiReview $review, ProgramApplication $application, ProgramRound $round): array
    {
        $mapped = $this->map($round, $review->suggested_scores ?? [], true);

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
