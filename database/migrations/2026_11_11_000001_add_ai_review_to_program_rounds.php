<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Renamed from 2027_11_06_000001_...: a database that ran it under the old name must not fail when it runs again,
// so every column is added only if it is missing.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_rounds', function (Blueprint $table) {
            // manual | ai_assisted | ai_only. Chosen per round; locked once the round is published.
            if (! Schema::hasColumn('program_rounds', 'review_method')) {
                $table->string('review_method', 20)->default('manual')->after('rubric_mode');
            }
            if (! Schema::hasColumn('program_rounds', 'ai_only_acknowledged_at')) {
                $table->timestamp('ai_only_acknowledged_at')->nullable();
            }
            if (! Schema::hasColumn('program_rounds', 'ai_only_acknowledged_by')) {
                $table->foreignId('ai_only_acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('program_rounds', 'ai_review_config')) {
                $table->json('ai_review_config')->nullable();
            }
            if (! Schema::hasColumn('program_rounds', 'ai_review_notified_at')) {
                $table->timestamp('ai_review_notified_at')->nullable();
            }
            if (! Schema::hasColumn('program_rounds', 'ai_capacity_notified_at')) {
                $table->timestamp('ai_capacity_notified_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('program_rounds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_only_acknowledged_by');
            $table->dropColumn(['review_method', 'ai_only_acknowledged_at', 'ai_review_config', 'ai_review_notified_at', 'ai_capacity_notified_at']);
        });
    }
};
