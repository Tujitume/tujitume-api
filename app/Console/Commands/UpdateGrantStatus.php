<?php

namespace App\Console\Commands;

use App\Models\Grants\Grant;
use App\Models\Grants\Rounds\GrantRound;
use App\Service\Notification\GrantNotificationService;
use Illuminate\Console\Command;

class UpdateGrantStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:grant-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'It updates grant statuses based on their start_date and application_deadline. Grants that have reached their start_date will be marked as "open", while those that have passed their application_deadline will be marked as "closed".';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $notificationService = new GrantNotificationService();

        /* Open grants that reached start_date
        Grant::where('status', 'published')->whereDate('start_date', '<=', now())
            ->update(['status' => 'open']);

        // Close grants that passed deadline
        Grant::where('status', 'open')->whereDate('application_deadline', '<', now())
            ->update(['status' => 'closed']);
        */


        // Open grants % rounds
        $openedGrants = Grant::where('status', 'published')->whereDate('start_date', '<=', now())->get();
        foreach ($openedGrants as $grant) {
            $grant->update(['status' => 'open']);
            $notificationService->send('grant.opened', [$grant->user], [
                'grant_title' => $grant->grant_title, 'grant_id' => $grant->id,
            ]);
        }

        // Open grants
        $draftRounds = GrantRound::where('status', 'draft')->whereDate('open_date', '<=', now())->get();
        //foreach ($draftRounds as $round) {
            //$round->update(['status' => 'published']);
//            $notificationService->send('grant.opened', [$grant->user], [
//                'grant_title' => $grant->grant_title, 'grant_id' => $grant->id,
//            ]);
        //}

        // Close grants
        $closedGrants = Grant::where('status', 'open')->whereDate('application_deadline', '<', now())->get();
        foreach ($closedGrants as $grant) {
            $grant->update(['status' => 'closed']);
            $notificationService->send('grant.closed', [$grant->user], [
                'grant_title' => $grant->grant_title, 'grant_id' => $grant->id,
            ]);
        }

        // Closing soon (3 days)
        $closingSoonRounds = GrantRound::where('status', 'published')->whereDate('close_date', '=', now()->addDays(3))->get();
        foreach ($closingSoonRounds as $round) {
            $applicants = $round->applications()->with('user')->get()->pluck('user');
            $notificationService->send('round.closing_soon', $applicants->all(), [
                'round_name' => $round->round_name, 'grant_title' => $round->grant->grant_title,
                'days_left' => 3, 'grant_id' => $round->grant_id,
            ]);
        }
    }
}
