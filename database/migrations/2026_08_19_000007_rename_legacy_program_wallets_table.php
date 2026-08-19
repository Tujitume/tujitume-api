<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grant_wallets') && !Schema::hasTable('program_wallets')) {
            Schema::rename('grant_wallets', 'program_wallets');
        }

        if (Schema::hasTable('program_wallets') && Schema::hasColumn('program_wallets', 'grant_id')) {
            Schema::table('program_wallets', fn ($table) => $table->renameColumn('grant_id', 'program_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('program_wallets') && Schema::hasColumn('program_wallets', 'program_id')) {
            Schema::table('program_wallets', fn ($table) => $table->renameColumn('program_id', 'grant_id'));
        }

        if (Schema::hasTable('program_wallets') && !Schema::hasTable('grant_wallets')) {
            Schema::rename('program_wallets', 'grant_wallets');
        }
    }
};
