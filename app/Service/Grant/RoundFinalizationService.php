<?php

namespace App\Service\Grant;

use App\Models\ApplicationRoundHistory;
use App\Models\Grants\Rounds\GrantRound;
use App\Service\Notification\GrantNotificationService;

class RoundFinalizationService
{
    public function __construct()
    {
        $this->grantNotification = new GrantNotificationService();
    }
    public function sendFinalizationNotifications(
        GrantRound $round,
        array $advanced,
        array $notSelected,
        ?GrantRound $nextRound,
        bool $isFinalRound,
        int $awardedCount
    ): void {
        foreach ($advanced as $app) {
            $this->grantNotification->send('round.advanced', [$app->user], [
                'grant_title'    => $round->grant->grant_title,
                'round_name'     => $nextRound->round_name ?? 'Final Round',
                'application_id' => $app->id,
            ]);
        }

        foreach ($notSelected as $app) {
            $this->grantNotification->send('round.not_selected', [$app->user], [
                'grant_title' => $round->grant->grant_title,
                'round_name'  => $round->round_name,
            ]);
        }

        if ($isFinalRound && $awardedCount > 0) {
            foreach ($advanced as $app) {
                if ($app->status === 'awarded') {
                    $this->grantNotification->send('application.awarded', [$app->user], [
                        'grant_title'    => $round->grant->grant_title,
                        'amount'         => $app->awarded_amount,
                        'application_id' => $app->id,
                    ]);
                }
            }

            // Notify grant owner - funding setup ready
            $this->grantNotification->send('grant.awarded', [$round->grant->owner], [
                'grant_title'   => $round->grant->grant_title,
                'awarded_count' => $awardedCount,
                'total_amount'  => $awardedCount * $round->grant->funding_per_business,
                'grant_id'      => $round->grant->id,
            ]);
        }
    }

}
