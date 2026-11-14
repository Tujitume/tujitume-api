<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('program_applications as applications')
            ->join('program_rounds as rounds', 'rounds.id', '=', 'applications.current_round_id')
            ->where('applications.status', 'pending')
            ->where('applications.round_status', 'draft')
            ->where('rounds.round_number', 1)
            ->select('applications.id', 'applications.current_round_id', 'applications.updated_at')
            ->orderBy('applications.id')
            ->chunkById(200, function ($applications): void {
                foreach ($applications as $application) {
                    DB::table('program_applications')->where('id', $application->id)->update([
                        'round_status' => 'submitted',
                    ]);

                    DB::table('application_round_history')
                        ->where('application_id', $application->id)
                        ->where('round_id', $application->current_round_id)
                        ->whereNull('submitted_at')
                        ->update(['submitted_at' => $application->updated_at ?? now()]);
                }
            }, 'applications.id', 'id');
    }

    public function down(): void
    {
        // Submission state is user data and must not be changed back to draft.
    }
};
