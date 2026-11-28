<?php

namespace App\Service\Program;

use App\Models\ApplicationRoundHistory;
use App\Models\Programs\Rounds\ProgramRound;
use App\Service\Notification\ProgramNotificationService;

class RoundFinalizationService
{
    public function __construct()
    {
        $this->programNotification = new ProgramNotificationService();
    }
    public function sendFinalizationNotifications(
        ProgramRound $round,
        array $advanced,
        array $notSelected,
        ?ProgramRound $nextRound,
        bool $isFinalRound,
        int $awardedCount
    ): void {
        // One email per applicant: someone left out (e.g. past the winner limit) is only "not selected", and the
        // winners of the last round get the award email, not "you moved on"
        $leftOut = collect($notSelected)->pluck('id')->all();
        $advanced = array_values(array_filter($advanced, fn ($app) => !in_array($app->id, $leftOut, true)));

        foreach ($isFinalRound ? [] : $advanced as $app) {
            $this->programNotification->send('round.advanced', [$app->user], [
                'program_title'    => $round->program->program_title,
                'round_name'     => $nextRound->round_name ?? 'Final Round',
                'program_id'     => $round->program_id,
                'application_id' => $app->id,
            ]);
        }

        foreach ($notSelected as $app) {
            $this->programNotification->send('round.not_selected', [$app->user], [
                'program_title' => $round->program->program_title,
                'round_name'  => $round->round_name,
                'program_id'  => $round->program_id,
                'application_id' => $app->id,
            ]);
        }

        if ($isFinalRound && $awardedCount > 0) {
            foreach ($advanced as $app) {
                if ($app->status === 'awarded') {
                    $this->programNotification->send('application.awarded', [$app->user], [
                        'program_title'    => $round->program->program_title,
                        'amount'         => $app->awarded_amount,
                        'application_id' => $app->id,
                    ]);
                }
            }

            // Notify program owner - funding setup ready
            $this->programNotification->send('program.awarded', \App\Service\Program\ProgramTeam::members($round->program)->all(), [
                'program_title'   => $round->program->program_title,
                'awarded_count' => $awardedCount,
                'total_amount'  => $awardedCount * $round->program->funding_per_business,
                'program_id'      => $round->program->id,
            ]);
        }
    }

}
