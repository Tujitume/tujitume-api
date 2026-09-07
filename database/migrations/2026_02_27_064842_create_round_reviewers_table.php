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
        Schema::create('round_reviewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('program_rounds')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('reviewer_type', ['internal', 'external'])->default('internal');

            $table->enum('acceptance_status', [
                'pending',    // invited, not yet responded
                'accepted',   // reviewer accepted assignment
                'declined',   // reviewer declined
            ])->default('pending');

            $table->integer('max_apps_assigned')->nullable(); // For load balancing
            $table->integer('assigned_percentage')->nullable();
            $table->json('expertise_tags')->nullable(); // ['agri', 'tech', 'energy']

            $table->decimal('reviewer_fee', 10, 2)->nullable();
            $table->string('fee_currency', 10)->default('USD');
            

            $table->integer('queue_position')->nullable();
            // For round_robin: position in acceptance queue (1 = first to accept)
            $table->timestamp('accepted_at')->nullable();

            $table->timestamp('review_started_at')->nullable(); // when they first opened an app
            $table->timestamps();

            $table->unique(['round_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('round_reviewers');
    }
};
