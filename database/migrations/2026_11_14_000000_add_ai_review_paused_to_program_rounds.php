<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When the program owner stops the AI review of a round, nothing new is sent to the AI until they continue it.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('program_rounds', 'ai_review_paused_at')) {
            Schema::table('program_rounds', function (Blueprint $table) {
                $table->timestamp('ai_review_paused_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('program_rounds', 'ai_review_paused_at')) {
            Schema::table('program_rounds', function (Blueprint $table) {
                $table->dropColumn('ai_review_paused_at');
            });
        }
    }
};
