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
        Schema::create('milestone_completion_submissions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('milestone_id');
            $table->unsignedBigInteger('submitted_by');                 // business owner
            $table->unsignedInteger('attempt_number')->default(1);

            // Proof of completion
            $table->text('completion_report')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->json('proof_files')->nullable();                    // array of deal_room_document IDs

            // Grant owner decision
            $table->enum('decision', [
                'pending',
                'approved',             // milestone complete, next can begin
                'rejected',             // corrections required
                'audit_requested',
            ])->default('pending');

            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();

            $table->foreign('milestone_id')
                ->references('id')->on('grant_milestones')->onDelete('restrict');
            $table->foreign('submitted_by')
                ->references('id')->on('users')->onDelete('restrict');
            $table->foreign('decided_by')
                ->references('id')->on('users')->onDelete('set null');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_completion_submissions');
    }
};
