<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the import holds after the person fixed it on the page (program details, rounds, question rows),
 * so the fixes survive a reload without going back to Excel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_imports', function (Blueprint $table) {
            $table->json('data')->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('program_imports', function (Blueprint $table) {
            $table->dropColumn('data');
        });
    }
};
