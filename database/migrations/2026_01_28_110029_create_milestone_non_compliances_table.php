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
        Schema::create('milestone_non_compliances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('milestone_id')->constrained('milestones')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users');

            // AMAP state
            $table->enum('stage', [
                'non_compliant',     // Stage 1
                'response_window',   // Stage 2
                'ipm',               // Stage 3
                'pid',               // Stage 4
                'sanctioned',        // Stage 5
                'resolved'
            ]);

            // Why triggered
            $table->enum('trigger_reason', [
                'missed_deadline',
                'failed_rme',
                'failed_extension'
            ]);

            // Deadlines
            $table->timestamp('response_deadline')->nullable(); // +72h
            $table->timestamp('ipm_started_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamp('pid_started_at')->nullable();
            $table->boolean('sanctioned')->default(false);

            // Owner action
            $table->enum('owner_response_type', [
                'completion_proof',
                'rmep',
                'other',
                'none'
            ])->default('none');

            $table->text('owner_response_note')->nullable();

            // Investor outcome
            $table->enum('investor_decision', [
                'continue',
                'freeze',
                'dispute'
            ])->nullable();

            // Final result
            $table->enum('resolution_result', [
                'continued',
                'restructured',
                'refunded',
                'blacklisted'
            ])->nullable();

            $table->timestamps();

            $table->unique('milestone_id'); // only one AMAP per milestone
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_non_compliances');
    }
};
