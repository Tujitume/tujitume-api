<?php

namespace App\Service\Program;

use App\Models\ApplicationRoundHistory;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;

/**
 * Where one application stands in one round, as the program team should see it.
 *
 * Round 1 is submitted by applying. From round 2 on, an advanced applicant is placed in the round with nothing handed
 * in: until they submit, the team sees the round's setup and why there is nothing to read yet, never half-saved
 * answers, and cannot score or advance them.
 */
class RoundParticipation
{
    private const SUBMITTED_STATUSES = ['submitted', 'assigned', 'under_review', 'scored'];

    /**
     * What happened to the applicant in a round. An applicant who left a round while its record still says "in progress"
     * was advanced by an older version that never closed it, so that counts as advanced.
     */
    public static function outcome(?ApplicationRoundHistory $history, bool $isCurrent): ?string
    {
        return $history?->outcome === 'in_progress' && ! $isCurrent ? 'advanced' : $history?->outcome;
    }

    public static function for(ProgramApplication $application, ProgramRound $round, ?ApplicationRoundHistory $history = null): array
    {
        $history ??= ApplicationRoundHistory::where('application_id', $application->id)
            ->where('round_id', $round->id)
            ->first();

        $isCurrent = (int) $application->current_round_id === (int) $round->id;
        $inRound = $isCurrent || $history !== null;
        $firstRound = (int) $round->round_number === 1;
        $movedOn = $history && in_array(self::outcome($history, $isCurrent), ['advanced', 'awarded'], true);
        $submittedNow = $isCurrent && in_array((string) $application->round_status, self::SUBMITTED_STATUSES, true);

        $submitted = $inRound && ($firstRound || $history?->submitted_at || $submittedNow || $movedOn);

        $state = 'submitted';
        $reason = null;
        if (! $inRound) {
            $state = 'not_in_round';
            $reason = 'This applicant has not reached ' . $round->round_name . '. They appear here once they are advanced into it.';
        } elseif (! $submitted) {
            $name = $round->round_name;
            $left = $history && ($history->exited_at || $history->outcome === 'not_selected');
            $closed = in_array($round->status, ['closed', 'in_review', 'finalized'], true)
                || ($round->close_date && $round->close_date->copy()->endOfDay()->isPast());

            if ($left) {
                $state = 'not_submitted';
                $reason = 'The applicant did not submit ' . $name . ' before they left the program.';
            } elseif ($openReason = $round->notOpenReason()) {
                $state = 'not_open';
                $reason = $name . ' has not opened yet' . ($round->open_date ? ' (it opens on ' . $round->open_date->format('j M Y') . ')' : '')
                    . '. The applicant can answer and submit once it opens. Their answers appear here after they submit.';
            } elseif ($closed) {
                $state = 'missed';
                $reason = $name . ' closed' . ($round->close_date ? ' on ' . $round->close_date->format('j M Y') : '') . ' and the applicant did not submit it.';
            } else {
                $state = 'awaiting_submission';
                $reason = 'The applicant was advanced to ' . $name . ' and has not submitted it yet. Their answers and documents appear here once they submit.';
            }
        }

        return [
            'round_id' => $round->id,
            'round_number' => (int) $round->round_number,
            'round_name' => $round->round_name,
            'state' => $state,
            'submitted' => $submitted,
            'is_current' => $isCurrent,
            'entered_at' => $history?->entered_at,
            'submitted_at' => $history?->submitted_at,
            'outcome' => $history?->outcome,
            'reason' => $reason,
            // Scoring and advancing need something to read, and an open round to record the result in
            'can_review' => $submitted && $isCurrent && $round->status !== 'finalized',
        ];
    }
}
