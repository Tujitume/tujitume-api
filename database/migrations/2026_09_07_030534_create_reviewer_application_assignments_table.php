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
        Schema::create('reviewer_application_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('program_rounds')->onDelete('cascade');
            $table->foreignId('reviewer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('application_id')->constrained('program_applications')->onDelete('cascade');

            $table->enum('status', [
                'unassigned',    // queued but not yet confirmed to this reviewer
                'assigned',      // confirmed to this reviewer
                'in_review',     // reviewer opened/started this app
                'completed',     // reviewer submitted score for this app
            ])->default('assigned');

            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->unique(['round_id', 'application_id']); // one reviewer per app per round
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviewer_application_assignments');
    }
};
