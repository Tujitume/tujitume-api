<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The completion proof saves why a milestone ran late, but the column did not exist, so sending the proof failed every time. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('milestone_execution_documents', 'delay_reason')) {
            Schema::table('milestone_execution_documents', function (Blueprint $table) {
                $table->string('delay_reason', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('milestone_execution_documents', 'delay_reason')) {
            Schema::table('milestone_execution_documents', fn (Blueprint $table) => $table->dropColumn('delay_reason'));
        }
    }
};
