<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's enum columns are VARCHAR on SQLite/PostgreSQL. MySQL needs the
        // allowed values expanded explicitly for final approval and modification requests.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE milestone_verifications MODIFY verification_type ENUM('mprv', 'mid_milestone', 'final_approval') NOT NULL DEFAULT 'mprv'");
            DB::statement("ALTER TABLE milestone_verifications MODIFY decision ENUM('pending', 'approved', 'rejected', 'audit_requested', 'modification_requested') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE milestone_verifications MODIFY decision ENUM('pending', 'approved', 'rejected', 'audit_requested') NOT NULL DEFAULT 'pending'");
            DB::statement("ALTER TABLE milestone_verifications MODIFY verification_type ENUM('mprv', 'mid_milestone') NOT NULL DEFAULT 'mprv'");
        }
    }
};
