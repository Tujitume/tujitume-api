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
        Schema::create('milestone_pre_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_id')->constrained('program_milestones')->onDelete('cascade');
            $table->enum('verification_type', ['mprv', 'mid_milestone', 'final_approval']);
            $table->enum('status', ['pending', 'agreed', 'rejected', 'final_rejected'])->default('pending');
            $table->unsignedTinyInteger('rejection_count')->default(0);
            $table->foreignId('submitted_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['milestone_id', 'verification_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_pre_agreements');
    }
};
