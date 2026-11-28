<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
        $schedule->command('send:milestone_reminder')->hourly();
        $schedule->command('run:service-cron-actions')->hourly();
        // Not scheduled: it paid a provider by Stripe 7 days after "Done" without the customer's approval, outside the
        // approve-and-release flow (ServiceBookingFlow::approve, LIPR). A service milestone's money moves only on approval.
        // $schedule->command('release:service_milestone_payment')->hourly();
        $schedule->command('send:bid_approval_reminder')->hourly();

        // Hourly, so programs open and close close to their exact moments (and each closing reminder goes once)
        $schedule->command('update:program-status')->hourly()->withoutOverlapping(30)->onOneServer();

        // AI-assisted / AI-only review: retries, owner and reviewer notices, capacity watch; and purge of stored document text
        $schedule->command('ai-reviews:sweep')->hourly()->withoutOverlapping(30)->onOneServer();
        $schedule->command('ai-reviews:purge-extractions')->daily()->onOneServer();

        // 7-day demo accounts end at their exact moment (UTC instant): reminder 48 h before, then the account is deleted
        // "Your plan ends soon", 3 days before a subscription ends (once per plan)
        $schedule->command('subscriptions:remind-ending')->hourly()->withoutOverlapping(30)->onOneServer();
        $schedule->command('demo-trials:sweep')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
