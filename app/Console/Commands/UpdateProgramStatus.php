<?php

namespace App\Console\Commands;

use App\Models\Programs\Program;
use App\Models\Programs\Rounds\ProgramRound;
use App\Service\Notification\ProgramNotificationService;
use Illuminate\Console\Command;

class UpdateProgramStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:program-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Updates program statuses at their exact opening and closing moments, and reminds applicants three days before a round closes.';

    /** It runs hourly: each run reminds about the rounds whose close falls in one hour, exactly three days ahead. */
    private const REMINDER_WINDOW_MINUTES = 60;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $notificationService = new ProgramNotificationService();
        $now = now();

        // Programs whose opening moment has passed
        $openedPrograms = Program::where('status', 'published')->whereNotNull('application_opens_at')->where('application_opens_at', '<=', $now)->get();
        foreach ($openedPrograms as $program) {
            $program->update(['status' => 'open']);
            $notificationService->send('program.opened', [$program->user], [
                'program_title' => $program->program_title, 'program_id' => $program->id,
            ]);
        }

        // Programs whose closing moment has passed
        $closedPrograms = Program::where('status', 'open')->whereNotNull('application_closes_at')->where('application_closes_at', '<', $now)->get();
        foreach ($closedPrograms as $program) {
            $program->update(['status' => 'closed']);
            $notificationService->send('program.closed', [$program->user], [
                'program_title' => $program->program_title, 'program_id' => $program->id,
            ]);
        }

        // Later rounds reaching their opening moment: tell the applicants waiting in them (once per round). Only rounds
        // that opened in the last day, so rounds that opened long ago are never announced late.
        $openedRounds = ProgramRound::where('status', 'published')
            ->where('round_number', '>', 1)
            ->whereNull('opened_notified_at')
            ->whereNotNull('opens_at')
            ->whereBetween('opens_at', [$now->copy()->subDay(), $now])
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', $now))
            ->get();
        $roundOpened = new \App\Service\Program\RoundOpenedNotifier();
        foreach ($openedRounds as $round) {
            $roundOpened->notify($round);
        }

        // Closing soon: rounds that close in three days (each round falls in exactly one run's window)
        $remindUntil = $now->copy()->addDays(3);
        $closingSoonRounds = ProgramRound::where('status', 'published')
            ->where('closes_at', '>', $remindUntil->copy()->subMinutes(self::REMINDER_WINDOW_MINUTES))
            ->where('closes_at', '<=', $remindUntil)
            ->get();
        foreach ($closingSoonRounds as $round) {
            // Only those who have not handed the round in yet: a reminder to someone who already submitted is noise
            $applicants = $round->applications()->with('user')
                ->where(fn ($q) => $q->whereNull('round_status')->orWhere('round_status', 'draft'))
                ->get()->pluck('user')->filter()->values();
            if ($applicants->isEmpty()) {
                continue;
            }
            $notificationService->send('round.closing_soon', $applicants->all(), [
                'round_name' => $round->round_name, 'program_title' => $round->program->program_title,
                'days_left' => 3, 'program_id' => $round->program_id,
                'closes_at' => \App\Support\ProgramSchedule::describe($round->closesAt(), $round->scheduleTimezone()),
            ]);
        }
    }
}
