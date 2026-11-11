<?php

namespace App\Service\Program;

use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;

/**
 * Averages an application's scores for a round and applies the result to the application.
 * Human scores always take precedence: AI rows count only while no human has scored.
 */
class ScoreAverager
{
    public function average(int $applicationId, int $roundId): float
    {
        $base = ApplicationScore::where('application_id', $applicationId)->where('round_id', $roundId);

        $human = (clone $base)->where(function ($q) {
            $q->whereNull('source')->orWhere('source', 'human');
        });

        $query = (clone $human)->exists() ? $human : $base;

        return round((float) $query->avg('total_score'), 2);
    }

    /** Same updates ApplicationScoreController has always made after a score is saved. */
    public function apply(ProgramApplication $application, ProgramRound $round): float
    {
        $avgScore = $this->average($application->id, $round->id);

        $application->average_score = $avgScore;
        $application->round_status = 'scored';

        $application->is_eligible_to_advance = match ($round->advancement_mode) {
            'score_threshold' => $avgScore >= $round->score_threshold,
            'fixed_quota' => true,
            'manual' => true,
            default => false,
        };

        $application->save();

        return $avgScore;
    }
}
