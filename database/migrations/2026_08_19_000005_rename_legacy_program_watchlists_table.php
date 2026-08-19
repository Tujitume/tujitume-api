<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grant_watchlists') && !Schema::hasTable('program_watchlists')) {
            Schema::rename('grant_watchlists', 'program_watchlists');
        }

        if (Schema::hasTable('program_watchlists') && Schema::hasColumn('program_watchlists', 'grant_owner_id')) {
            Schema::table('program_watchlists', fn ($table) => $table->renameColumn('grant_owner_id', 'program_owner_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('program_watchlists') && Schema::hasColumn('program_watchlists', 'program_owner_id')) {
            Schema::table('program_watchlists', fn ($table) => $table->renameColumn('program_owner_id', 'grant_owner_id'));
        }

        if (Schema::hasTable('program_watchlists') && !Schema::hasTable('grant_watchlists')) {
            Schema::rename('program_watchlists', 'grant_watchlists');
        }
    }
};
