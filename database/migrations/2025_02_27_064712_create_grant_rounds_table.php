<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('grant_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grant_id')->constrained()->onDelete('cascade');
            $table->string('round_name', 100); // "Public Application", "Semi-Finalist", etc.
            $table->integer('round_number')->default(1); // 1, 2, 3, 4
            $table->date('open_date')->nullable();
            $table->date('close_date')->nullable();
            $table->date('review_period_end')->nullable();
            $table->date('announcement_date')->nullable();

            // Evaluation Settings
            $table->enum('rubric_mode', ['weighted', 'simple_total', 'pass_fail'])->default('weighted');
            $table->json('scoring_criteria')->nullable(); // [{name, description, weight, score_range}]
            $table->json('knockout_questions')->nullable(); // [{question, required_answer}]
            $table->json('required_documents')->nullable(); // ['pitch_deck', 'financials']

            // Reviewer Assignment
            $table->enum('assignment_type', ['owner_only', 'internal', 'external'])->default('owner_only');
            $table->enum('assignment_method', ['manual', 'round_robin', 'load_balanced'])->default('manual');
            $table->integer('min_reviewers_required')->default(1);

            // Advancement Settings
            $table->enum('advancement_mode', ['manual', 'score_threshold', 'fixed_quota'])->default('manual');
            $table->decimal('score_threshold', 5, 2)->nullable(); // e.g., 75.00
            $table->integer('max_advancing')->nullable(); // Cap on advancing applicants
            $table->enum('tie_breaker_rule', ['allow_over_cap', 'secondary_metric', 'manual'])->nullable();

            $table->enum('status', ['draft', 'published', 'closed', 'in_review', 'finalized'])->default('draft');
            $table->timestamps();

            $table->unique(['grant_id', 'round_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grant_rounds');
    }
};
