<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ISSUE-29 / ISSUE-41 (e2e test 2026-11-27): applicants turned down in a round ("not_selected") before the fix kept their
 * application status (pending / approved / under_review), so lists and counts treated them as still in the running.
 * They are rejected, as the code now records it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('program_applications')
            ->where('round_status', 'not_selected')
            ->whereIn('status', ['pending', 'approved', 'under_review'])
            ->update(['status' => 'rejected', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Not reversible: which of them were pending or approved before is not kept
    }
};
