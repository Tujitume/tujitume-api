<?php

namespace App\Service\Program;

use App\Models\ApplicationRoundHistory;

class RoundHistoryService
{
    public function create(int $round_id, int $application_id, int $round_number, string $outcome)
    {
        // Entered on a round
        ApplicationRoundHistory::create([
            'application_id' => $application_id,
            'round_id' => $round_id,
            'round_number' => $round_number,
            'entered_at' => now(),
            'outcome' => $outcome
        ]);

    }

    public function closeAndCreate($application, $nextRound, int $total_app, string $outcome){
        // Close current round history
        ApplicationRoundHistory::where('application_id', $application->id)
            ->where('round_id', $application->current_round_id)
            ->update([
                'exited_at' => now(),
                'outcome' => 'advanced',
                'average_score' => $application->average_score,
                'rank_in_round' => 'N/A',
                'total_applicants_in_round' => $total_app,
                'outcome_notes' => 'N/A'
            ]);

        // Create new round history entry
        ApplicationRoundHistory::create([
            'application_id' => $application->id,
            'round_id' => $nextRound->id,
            'round_number' => $nextRound->round_number,
            'entered_at' => now(),
            'outcome' => $outcome
        ]);

        //$application->update(['current_round_id' => $nextRound->id]);
    }

    public function update($application, string $outcome, string $note = 'N/A')
    {
        ApplicationRoundHistory::where('application_id', $application->id)
            ->where('round_id', $application->current_round_id)
            ->update([
                'exited_at' => now(),
                'outcome' => $outcome,
                'average_score' => $application->average_score,
                'outcome_notes' => $note
            ]);

        if (in_array($outcome, ['not_selected', 'withdrawn'])) {
            $application->update(['round_status' => 'not_selected']);
        }
    }
}
