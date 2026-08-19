<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grant_profiles') && !Schema::hasTable('program_profiles')) {
            Schema::rename('grant_profiles', 'program_profiles');
        }

        if (Schema::hasTable('program_profiles') && Schema::hasColumn('program_profiles', 'grant_owner_id')) {
            Schema::table('program_profiles', fn ($table) => $table->renameColumn('grant_owner_id', 'program_owner_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('program_profiles') && Schema::hasColumn('program_profiles', 'program_owner_id')) {
            Schema::table('program_profiles', fn ($table) => $table->renameColumn('program_owner_id', 'grant_owner_id'));
        }

        if (Schema::hasTable('program_profiles') && !Schema::hasTable('grant_profiles')) {
            Schema::rename('program_profiles', 'grant_profiles');
        }
    }
};
