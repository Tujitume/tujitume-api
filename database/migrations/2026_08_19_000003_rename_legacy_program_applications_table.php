<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grant_applications') && !Schema::hasTable('program_applications')) {
            Schema::rename('grant_applications', 'program_applications');
        }

        if (Schema::hasTable('program_applications')) {
            if (Schema::hasColumn('program_applications', 'grant_id')) Schema::table('program_applications', fn ($table) => $table->renameColumn('grant_id', 'program_id'));
            if (Schema::hasColumn('program_applications', 'grant_owner_id')) Schema::table('program_applications', fn ($table) => $table->renameColumn('grant_owner_id', 'program_owner_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('program_applications')) {
            if (Schema::hasColumn('program_applications', 'program_id')) Schema::table('program_applications', fn ($table) => $table->renameColumn('program_id', 'grant_id'));
            if (Schema::hasColumn('program_applications', 'program_owner_id')) Schema::table('program_applications', fn ($table) => $table->renameColumn('program_owner_id', 'grant_owner_id'));
        }

        if (Schema::hasTable('program_applications') && !Schema::hasTable('grant_applications')) {
            Schema::rename('program_applications', 'grant_applications');
        }
    }
};
