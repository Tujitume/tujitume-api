<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A file-upload question can now take several files (the owner sets the maximum). `file_path` keeps the first file, so
     * everything that reads one file still works; `file_paths` holds every file of the answer, in order.
     */
    public function up(): void
    {
        Schema::table('application_round_responses', function (Blueprint $table) {
            $table->json('file_paths')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('application_round_responses', function (Blueprint $table) {
            $table->dropColumn('file_paths');
        });
    }
};
