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
        Schema::create('milestone_pre_release_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('milestone_id');
            $table->unsignedBigInteger('investor_id');

            // Checklist options (true/false toggles)
            $table->boolean('written_statement')->default(true);
            $table->json('proof_of_procurement')->nullable();
            $table->json('financial_reasonableness')->nullable();
            $table->json('risk_flags')->nullable();
            $table->json('media_proof')->nullable();

            $table->string('status')->default('pending'); // approved-rejected-pm_audit
            // pending → waiting for BO upload
            // submitted → BO uploaded docs
            // approved → investor approved
            // rejected → investor rejected
            // auto_approved → system auto approves
            // escalated → admin involvement

            $table->unsignedTinyInteger('reject_count')->default(0);
            $table->enum('vote', ['approve','reject','audit'])->nullable();
            $table->decimal('weight', 12, 2); // SNAPSHOT weight at creation

            $table->timestamps();

            $table->foreign('milestone_id')->references('id')->on('milestones')->onDelete('cascade');
            $table->foreign('investor_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_pre_release_requests');
    }
};
