<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DECISION-3 / owner answers 27 Nov: when an awarded application's last milestone finished (status 'completed'). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('program_applications', 'completed_at')) return;
        Schema::table('program_applications', fn (Blueprint $table) => $table->timestamp('completed_at')->nullable()->after('awarded_at'));
    }

    public function down(): void
    {
        if (! Schema::hasColumn('program_applications', 'completed_at')) return;
        Schema::table('program_applications', fn (Blueprint $table) => $table->dropColumn('completed_at'));
    }
};
