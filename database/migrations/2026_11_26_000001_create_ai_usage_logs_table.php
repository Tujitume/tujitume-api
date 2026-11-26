<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('round_id')->nullable()->constrained('program_rounds')->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('program_applications')->nullOnDelete();
            $table->foreignId('evaluation_run_id')->nullable()->constrained('application_ai_reviews')->nullOnDelete();
            $table->string('provider', 40);
            $table->string('model', 160)->nullable();
            $table->string('request_id', 190)->nullable();
            $table->string('operation', 50)->default('review');
            foreach (['input_tokens', 'output_tokens', 'cached_tokens', 'reasoning_tokens', 'total_tokens'] as $column) $table->unsignedBigInteger($column)->nullable();
            foreach (['input_cost', 'output_cost', 'cached_cost', 'total_cost'] as $column) $table->decimal($column, 20, 12)->nullable();
            $table->string('cost_basis', 20)->default('estimated');
            $table->char('currency', 3)->default('USD');
            $table->json('pricing_snapshot')->nullable();
            $table->string('status', 20)->default('unknown');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'request_id']);
            foreach (['user_id', 'workspace_id', 'program_id', 'round_id', 'application_id', 'evaluation_run_id', 'provider', 'model', 'created_at'] as $index) $table->index($index);
        });
        Schema::table('application_ai_reviews', function (Blueprint $table) {
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('application_ai_reviews', fn (Blueprint $table) => $table->dropConstrainedForeignId('initiated_by_user_id'));
        Schema::dropIfExists('ai_usage_logs');
    }
};
