<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 5 (Import) runs as a background job. It writes how far it has got here, program by program, so the page
 * can show it, a program already created is never created twice, and a stopped import can carry on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_imports', function (Blueprint $table) {
            // { total, done, created, skipped, failed, results: { "<code>": { status, program_id?, message? } } }
            $table->json('progress')->nullable()->after('data');
            $table->timestamp('started_at')->nullable()->after('progress');
            $table->timestamp('finished_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('program_imports', function (Blueprint $table) {
            $table->dropColumn(['progress', 'started_at', 'finished_at']);
        });
    }
};
