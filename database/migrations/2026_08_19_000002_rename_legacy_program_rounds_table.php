<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grant_rounds') && !Schema::hasTable('program_rounds')) {
            Schema::rename('grant_rounds', 'program_rounds');
        }

        if (Schema::hasTable('program_rounds') && Schema::hasColumn('program_rounds', 'grant_id')) {
            Schema::table('program_rounds', fn ($table) => $table->renameColumn('grant_id', 'program_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('program_rounds') && Schema::hasColumn('program_rounds', 'program_id')) {
            Schema::table('program_rounds', fn ($table) => $table->renameColumn('program_id', 'grant_id'));
        }

        if (Schema::hasTable('program_rounds') && !Schema::hasTable('grant_rounds')) {
            Schema::rename('program_rounds', 'grant_rounds');
        }
    }
};
