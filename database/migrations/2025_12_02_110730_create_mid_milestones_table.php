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
        Schema::create('mid_milestones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('milestone_id');
            $table->unsignedBigInteger('created_by'); // BO user

            // Required
            $table->text('progress_statement');
            $table->integer('progress_percent')->default(0);
            $table->text('timeline_forecast');

            // Optional
            $table->text('challenges')->nullable();

            // Single ZIP file for all proofs
            $table->string('proofs_zip')->nullable();
            $table->json('proofs_requested')->nullable();

            // Uploaded files (photos/videos/receipts/etc.)
//            $table->json('photo_file')->nullable();
//            $table->json('video_file')->nullable();
//            $table->json('invoice_file')->nullable();
//            $table->json('work_log_file')->nullable();
//            $table->json('supplier_confirmation_file')->nullable();
//            $table->json('screenshot_file')->nullable();

            // Tracking flow
            $table->string('status')->default('submitted');
            // submitted, pending_review, approved, rejected, pm_audit, completed, escalated

            // For "two failed attempts"
            $table->integer('attempt_count')->default(0);
            $table->integer('approve_count')->default(0);
            $table->integer('reject_count')->default(0);
            $table->integer('pm_audit_count')->default(0);

            // PM Audit
            $table->string('pm_audit_status')->nullable(); // pass, partial, fail
            $table->text('pm_audit_notes')->nullable();

            $table->foreign('milestone_id')->references('id')->on('milestones')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mid_milestones');
    }
};
