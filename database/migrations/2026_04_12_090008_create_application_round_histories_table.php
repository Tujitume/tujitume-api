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
        Schema::create('application_round_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('program_applications')->onDelete('cascade');
            $table->foreignId('round_id')->constrained('program_rounds')->onDelete('cascade');

            // Round participation details
            $table->integer('round_number'); // 1, 2, 3, etc. (denormalized for easy querying)
            $table->timestamp('entered_at'); // When applicant entered this round
            $table->timestamp('submitted_at')->nullable(); // When they submitted their application
            $table->timestamp('exited_at')->nullable(); // When they left this round (advanced/rejected)

            // Round performance
            $table->decimal('average_score', 5, 2)->nullable(); // Their average score in this round
            $table->integer('rank_in_round')->nullable(); // Their ranking (1st, 2nd, 3rd, etc.)
            $table->integer('total_applicants_in_round')->nullable(); // Total applicants in this round

            // Round outcome
            $table->enum('outcome', [
                'in_progress',     // Currently in this round
                'advanced',        // Moved to next round
                'not_selected',    // Didn't advance
                'withdrawn',       // Applicant withdrew
                'awarded'          // Final round - received program
            ])->default('in_progress');

            $table->text('outcome_notes')->nullable(); // Why they advanced/didn't advance

            // Reviewer feedback summary
            $table->json('reviewer_feedback_summary')->nullable(); // Aggregated reviewer comments

            $table->timestamps();

            // Indexes
            $table->unique(['application_id', 'round_id'], 'unique_app_round_history');
            $table->index(['application_id', 'round_number']);
            $table->index('outcome');
            $table->index('entered_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_round_history');
    }
};
