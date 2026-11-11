<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_rounds', function (Blueprint $table) {
            // manual | ai_assisted | ai_only. Chosen per round; locked once the round is published.
            $table->string('review_method', 20)->default('manual')->after('rubric_mode');
            $table->timestamp('ai_only_acknowledged_at')->nullable();
            $table->foreignId('ai_only_acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('ai_review_config')->nullable();
            $table->timestamp('ai_review_notified_at')->nullable();
            $table->timestamp('ai_capacity_notified_at')->nullable();
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
