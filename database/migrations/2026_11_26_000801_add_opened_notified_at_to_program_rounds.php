<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When applicants were told a round is open, so the "round opened" email goes out exactly once: at publish when it
// opens straight away, else from the hourly job at its opening moment.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('program_rounds', 'opened_notified_at')) {
            Schema::table('program_rounds', function (Blueprint $table) {
                $table->timestamp('opened_notified_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('program_rounds', 'opened_notified_at')) {
            Schema::table('program_rounds', function (Blueprint $table) {
                $table->dropColumn('opened_notified_at');
            });
        }
    }
};
