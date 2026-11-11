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
        $schedule->command('release:service_milestone_payment')->hourly();
        $schedule->command('send:bid_approval_reminder')->hourly();

        $schedule->command('update:program-status')->daily();

        // AI-assisted / AI-only review: retries, owner and reviewer notices, capacity watch; and purge of stored document text
        $schedule->command('ai-reviews:sweep')->hourly()->withoutOverlapping(30)->onOneServer();
        $schedule->command('ai-reviews:purge-extractions')->daily()->onOneServer();

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
