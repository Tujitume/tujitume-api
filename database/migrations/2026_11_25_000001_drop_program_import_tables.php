<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Program Import module was removed. This drops its tables and its migration records
 * from any database where it had been installed. Safe to run where they never existed.
 */
return new class extends Migration
{
    private const TABLES = [
        'program_import_links',
        'program_import_records',
        'program_import_rows',
        'program_import_users',
        'program_import_tokens',
        'program_import_settings',
        'program_import_batches',
    ];

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        DB::table('migrations')->whereIn('migration', [
            '2026_11_16_000001_create_program_import_tables',
            '2026_11_17_000001_create_program_import_tokens_table',
            '2026_11_17_000002_create_program_import_settings_table',
        ])->delete();
    }

    public function down(): void
    {
        // The module is gone; nothing to restore.
    }
};
