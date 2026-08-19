<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('m_e_checkpoints') && Schema::hasColumn('m_e_checkpoints', 'grant_id')) {
            Schema::table('m_e_checkpoints', fn ($table) => $table->renameColumn('grant_id', 'program_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('m_e_checkpoints') && Schema::hasColumn('m_e_checkpoints', 'program_id')) {
            Schema::table('m_e_checkpoints', fn ($table) => $table->renameColumn('program_id', 'grant_id'));
        }
    }
};
