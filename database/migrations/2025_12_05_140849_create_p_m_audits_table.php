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
        Schema::create('p_m_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mid_milestone_id')->nullable();
            $table->unsignedBigInteger('pr_request_id')->nullable();
            $table->unsignedBigInteger('milestone_id')->nullable();

            // Store list of candidate PMs selected by investors (array of user IDs)
            $table->json('candidate_pm_ids')->nullable();

            // The PM who is officially selected
            $table->unsignedBigInteger('assigned_pm_id')->nullable();

            // PM audit types
            $table->enum('type', ['mid_milestone', 'pre_release', 'final_approval', 'grant_milestone', 'capital_milestone'])
                ->default('mid_milestone');

            // PM acceptance status
            $table->enum('status', ['pending', 'accepted', 'rejected'])
                ->default('pending');

            $table->timestamps();

            $table->foreign('mid_milestone_id')
                ->references('id')
                ->on('mid_milestones')
                ->cascadeOnDelete();

            $table->foreign('pr_request_id')
                ->references('id')
                ->on('milestone_pre_release_requests')
                ->cascadeOnDelete();

            $table->foreign('assigned_pm_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mid_p_m_audits');
    }
};
