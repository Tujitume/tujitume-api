<?php

namespace App\Service\Program;

use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Service\Notification\ProgramNotificationService;

/**
 * "Round N is open" for the applicants waiting in a later round (2, 3…), sent once per round: when it is published and
 * opens straight away, else by the hourly job at its exact opening moment. Round 1 has no waiting applicants (people
 * apply into it), so it is never sent for round 1. Best effort: a failed email never stops the caller.
 */
class RoundOpenedNotifier
{
    public function notify(ProgramRound $round): int
    {
        if ((int) $round->round_number <= 1 || $round->opened_notified_at) {
            return 0;
        }
        // Marked first, so two runs at once never both send
        $claimed = ProgramRound::query()->whereKey($round->id)->whereNull('opened_notified_at')->update(['opened_notified_at' => now()]);
        if (! $claimed) {
            return 0;
        }

        $round->loadMissing('program');
        $notifier = new ProgramNotificationService();
        $sent = 0;
        ProgramApplication::with('user')
            ->where('current_round_id', $round->id)
            ->where(fn ($q) => $q->whereNull('round_status')->orWhere('round_status', 'draft')) // not handed in yet
            ->get()
            ->each(function (ProgramApplication $app) use ($notifier, $round, &$sent) {
                if (! $app->user) {
                    return;
                }
                try {
                    $notifier->send('round.opened', [$app->user], [
                        'program_title' => $round->program->program_title,
                        'round_name' => $round->round_name,
                        'program_id' => $round->program_id,
                        'application_id' => $app->id,
                        'recipientName' => $app->user->first_name,
                        'closes_at' => \App\Support\ProgramSchedule::describe($round->closesAt(), $round->scheduleTimezone()),
                    ]);
                    $sent++;
                } catch (\Throwable $e) {
                    \Log::warning('Round opened notification failed: ' . $e->getMessage());
                }
            });

        return $sent;
    }
}
