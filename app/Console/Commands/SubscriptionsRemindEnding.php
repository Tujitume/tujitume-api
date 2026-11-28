<?php

namespace App\Console\Commands;

use App\Service\Subscription\SubscriptionReminder;
use Illuminate\Console\Command;

class SubscriptionsRemindEnding extends Command
{
    protected $signature = 'subscriptions:remind-ending';

    protected $description = 'Email whoever holds a plan '.SubscriptionReminder::DAYS_BEFORE.' days before it ends (once per plan)';

    public function handle(SubscriptionReminder $reminder): int
    {
        $this->info('Reminders sent: '.$reminder->run());

        return self::SUCCESS;
    }
}
