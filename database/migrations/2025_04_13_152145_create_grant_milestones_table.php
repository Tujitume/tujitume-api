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
        Schema::create('grant_milestones', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('app_id');                       // was plain int — now FK-safe
            $table->unsignedInteger('sequence_order');                  // NEW: 1,2,3… enforces order

            $table->string('title', 500)->nullable();
            $table->string('description', 1000)->nullable();

            $table->decimal('amount', 15, 2)->nullable();               // was int → decimal

            // Purpose type (section 6.1)
            $table->enum('purpose_type', [
                'capex',        // long-term assets
                'opex',         // operational costs
                'services',
                'mixed',
            ])->nullable();

            $table->text('expected_outcomes')->nullable();               // new (section 6.1)

            // Full lifecycle status (was tinyInt 0/1)
            $table->enum('status', [
                'pending',                  // defined, not yet submitted for MPRV
                'submitted',                // MPRV submitted by business owner
                'under_review',             // grant owner reviewing MPRV
                'audit_requested',          // PM assigned for audit (section 8)
                'revision_requested',        // sent back for corrections
                'approved',                 // ready for disbursement
                'rejected',                 // rejected
                'disbursing',               // payments being processed
                'completed',                // business owner submitted proof
                'completion_approved',      // grant owner signed off
                'completion_rejected',      // proof rejected, resubmission needed
            ])->default('pending');

            $table->boolean('is_template')->default(true);

            $table->enum('created_by_role', ['grant_owner', 'applicant'])->default('grant_owner');

            $table->json('allowed_edits')->nullable();

            // Fund release status (was tinyInt 0/1)
            $table->enum('fund_release_status', [
                'locked',       // not yet approved
                'approved',     // approved, awaiting payment
                'processing',   // payments being sent
                'released',     // all payments done
                'partial',      // some payments pending
            ])->default('locked');

            // Approval tracking
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Completion tracking (section 11)
            $table->text('completion_notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completion_approved_by')->nullable();
            $table->timestamp('completion_approved_at')->nullable();

            $table->string('document', 100)->nullable();
            $table->boolean('mprv_ready')->default(false);
            $table->boolean('mid_milestone_ready')->default(false);
            $table->boolean('final_approval_ready')->default(false);

            $table->boolean('mprv_required')->default(false);
            $table->boolean('mid_milestone_required')->default(false);
            $table->boolean('final_approval_required')->default(false);

            $table->text('verification_notes')->nullable();
            $table->text('mprv_notes')->nullable();
            $table->text('mid_milestone_notes')->nullable();
            $table->text('final_approval_notes')->nullable();


            $table->boolean('fund_released')->default(false);


            $table->unsignedInteger('duration_days')->nullable();  // estimated duration in days
            $table->date('estimated_completion_date')->nullable(); // kept from original

            $table->foreign('app_id')
                ->references('id')->on('grant_applications')->onDelete('cascade');
            $table->foreign('approved_by')
                ->references('id')->on('users')->onDelete('set null');
            $table->foreign('completion_approved_by')
                ->references('id')->on('users')->onDelete('set null');

            // Enforce unique ordering per application
            $table->unique(['app_id', 'sequence_order']);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grant_milestones');
    }
};
