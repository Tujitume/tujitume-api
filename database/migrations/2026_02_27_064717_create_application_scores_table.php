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
        Schema::create('application_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('grant_applications')->onDelete('cascade');
            $table->foreignId('round_id')->constrained('grant_rounds')->onDelete('cascade');
            $table->foreignId('reviewer_id')->constrained('users')->onDelete('cascade');

            $table->json('criterion_scores'); // [{criterion_name, score, comment}]
            $table->decimal('total_score', 5, 2);
            $table->text('overall_comment')->nullable();
            $table->timestamp('scored_at');
            $table->timestamps();

            $table->unique(['application_id', 'round_id', 'reviewer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_scores');
    }
};
