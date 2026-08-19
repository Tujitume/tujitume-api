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
    protected $description = 'It updates program statuses based on their start_date and application_deadline. Programs that have reached their start_date will be marked as "open", while those that have passed their application_deadline will be marked as "closed".';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $notificationService = new ProgramNotificationService();

        /* Open programs that reached start_date
        Program::where('status', 'published')->whereDate('start_date', '<=', now())
            ->update(['status' => 'open']);

        // Close programs that passed deadline
        Program::where('status', 'open')->whereDate('application_deadline', '<', now())
            ->update(['status' => 'closed']);
        */


        // Open programs % rounds
        $openedPrograms = Program::where('status', 'published')->whereDate('start_date', '<=', now())->get();
        foreach ($openedPrograms as $program) {
            $program->update(['status' => 'open']);
            $notificationService->send('program.opened', [$program->user], [
                'program_title' => $program->program_title, 'program_id' => $program->id,
            ]);
        }

        // Open programs
        $draftRounds = ProgramRound::where('status', 'draft')->whereDate('open_date', '<=', now())->get();
        //foreach ($draftRounds as $round) {
            //$round->update(['status' => 'published']);
//            $notificationService->send('program.opened', [$program->user], [
//                'program_title' => $program->program_title, 'program_id' => $program->id,
//            ]);
        //}

        // Close programs
        $closedPrograms = Program::where('status', 'open')->whereDate('application_deadline', '<', now())->get();
        foreach ($closedPrograms as $program) {
            $program->update(['status' => 'closed']);
            $notificationService->send('program.closed', [$program->user], [
                'program_title' => $program->program_title, 'program_id' => $program->id,
            ]);
        }

        // Closing soon (3 days)
        $closingSoonRounds = ProgramRound::where('status', 'published')->whereDate('close_date', '=', now()->addDays(3))->get();
        foreach ($closingSoonRounds as $round) {
            $applicants = $round->applications()->with('user')->get()->pluck('user');
            $notificationService->send('round.closing_soon', $applicants->all(), [
                'round_name' => $round->round_name, 'program_title' => $round->program->program_title,
                'days_left' => 3, 'program_id' => $round->program_id,
            ]);
        }
    }
}
