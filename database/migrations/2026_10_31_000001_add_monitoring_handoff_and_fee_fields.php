<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Some installations created this table from an older version of its
        // create migration. Existing tables are not changed when that migration
        // is edited, so add the handoff fields here when they are missing.
        if (! Schema::hasColumn('m_e_checkpoints', 'sent_at')) {
            Schema::table('m_e_checkpoints', function (Blueprint $table) {
                $table->timestamp('sent_at')->nullable();
            });
        }

        if (! Schema::hasColumn('m_e_checkpoints', 'respondent')) {
            Schema::table('m_e_checkpoints', function (Blueprint $table) {
                $table->string('respondent', 30)->default('applicant');
            });
        }

        DB::table('m_e_checkpoints')->whereNull('sent_at')->update([
            'sent_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)'),
        ]);
        DB::table('m_e_checkpoints')->where('require_site_visit', true)->update(['respondent' => 'site_visit_officer']);
    }

    public function down(): void
    {
        // The schema is defined by the original create-table migrations.
    }
};
