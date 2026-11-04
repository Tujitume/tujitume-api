<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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
