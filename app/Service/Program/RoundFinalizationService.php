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
        foreach ($advanced as $app) {
            $this->programNotification->send('round.advanced', [$app->user], [
                'program_title'    => $round->program->program_title,
                'round_name'     => $nextRound->round_name ?? 'Final Round',
                'application_id' => $app->id,
            ]);
        }

        foreach ($notSelected as $app) {
            $this->programNotification->send('round.not_selected', [$app->user], [
                'program_title' => $round->program->program_title,
                'round_name'  => $round->round_name,
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
            $this->programNotification->send('program.awarded', [$round->program->owner], [
                'program_title'   => $round->program->program_title,
                'awarded_count' => $awardedCount,
                'total_amount'  => $awardedCount * $round->program->funding_per_business,
                'program_id'      => $round->program->id,
            ]);
        }
    }

}
