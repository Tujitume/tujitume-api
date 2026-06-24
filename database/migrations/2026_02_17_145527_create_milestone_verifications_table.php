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
        Schema::create('milestone_verifications', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('milestone_id');
            $table->unsignedBigInteger('submitted_by');                 // business owner
            $table->unsignedInteger('attempt_number')->default(1);      // tracks resubmission count

            // Mandatory declarations (section 7.1C)
            $table->tinyInteger('conflict_of_interest_confirmed')->default(0);
            $table->tinyInteger('funds_usage_confirmed')->default(0);
            $table->text('additional_declarations')->nullable();
            $table->text('document')->nullable();

            $table->enum('verification_type', ['mprv', 'mid_milestone'])->default('mprv');

            // Grant owner decision — approve / reject / audit (NO voting, section 8)
            $table->enum('decision', [
                'pending',
                'approved',
                'rejected',
                'audit_requested',      // triggers PM assignment
            ])->default('pending');

            $table->unsignedBigInteger('decided_by')->nullable();       // grant owner
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();

            // Audit path (section 8 — Request Audit)
            $table->unsignedBigInteger('auditor_id')->nullable();       // project manager assigned
            $table->timestamp('audit_started_at')->nullable();
            $table->timestamp('audit_completed_at')->nullable();
            $table->text('audit_notes')->nullable();

            $table->foreign('milestone_id')
                ->references('id')->on('grant_milestones')->onDelete('restrict');
            $table->foreign('submitted_by')
                ->references('id')->on('users')->onDelete('restrict');
            $table->foreign('decided_by')
                ->references('id')->on('users')->onDelete('set null');
            $table->foreign('auditor_id')
                ->references('id')->on('users')->onDelete('set null');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_verifications');
    }
};
