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
        Schema::create('milestones', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('listing_id')->nullable();
            $table->foreign('listing_id')
                ->references('id')->on('listings')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('set null');

            $table->string('title', 300)->nullable();
            $table->integer('amount')->nullable(); // required investment for this milestone

            // sum of accepted bids for this milestone (updated automatically)
            $table->decimal('funding_collected', 10, 2)->default(0);
            $table->decimal('pending_collected', 10, 2)->default(0);

            $table->tinyInteger('n_o_days')->nullable(); // duration for Exec
            $table->tinyInteger('funding_duration')->nullable(); // duration in days

            // Status lifecycle (created → to_do → in_progress → at_risk → done → overdue)
            $table->enum('status', [
                'locked',
                'to_do',
                'fully_funded',
                'in_progress',
                'done',
                'at_risk',
                'overdue',
                'failed',
                'in_pr_audit',
                'in_mid_audit',
                'in_final_audit',
                'non_compliant',
                'admin_review',
                'reviewed',
                'extended',
                'execution_submitted',
                'continuation_triggered',
                'rmep_submitted',
                'pr_approved',
                'pr_rejected',
            ])->default('to_do');

            // Deadline fields
            $table->date('start_date')->nullable();
            $table->date('deadline_date')->nullable(); // manually set OR (start_date + n_o_days)
            $table->date('exec_deadline_date')->nullable(); // manually set OR (start_date + n_o_days)
            $table->date('expected_end_date')->nullable(); // auto-calculated mirror
            $table->string('due_in', 200)->nullable();

            // Additional lifecycle controls
            $table->unsignedTinyInteger('progress_percentage')->default(0); // 0–100
            $table->string('risk_level', 50)->nullable(); // low, medium, high

            // Funding progression
            $table->boolean('is_funded')->default(false);
            $table->boolean('mid_milestone_started ')->default(false);
            $table->boolean('final_approval_started ')->default(false);
            $table->boolean('pre_release_notified')->default(false);

            // RMEP verifications counts
            $table->integer('rmep_approved')->default(0);
            $table->integer('rmep_rejected')->default(0);

            //Pre release vote counts
            $table->integer('pr_approved')->default(0);
            $table->integer('pr_rejected')->default(0);
            $table->integer('pr_audit')->default(0);

            $table->integer('mid_approved')->default(0);
            $table->integer('mid_rejected')->default(0);

            $table->string('document', 200)->nullable();
            $table->decimal('share', 10, 2)->nullable();
            $table->boolean('extended_before')->default(false);
            $table->boolean('continuation_approved')->default(false);

            $table->boolean('fund_released_75')->default(false);
            $table->boolean('fund_released')->default(false);

            $table->enum('payout_method', ['stripe', 'lipr'])->default('stripe');

            $table->boolean('active')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestones');
    }
};
