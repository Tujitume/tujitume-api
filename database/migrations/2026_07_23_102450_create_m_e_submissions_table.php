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
        Schema::create('m_e_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkpoint_id')->constrained('me_checkpoints')->onDelete('cascade');
            $table->foreignId('app_id')->constrained('grant_applications')->onDelete('cascade');
            $table->foreignId('submitted_by')->constrained('users')->onDelete('cascade');
            $table->text('written_report')->nullable();
            $table->json('kpi_actual_values')->nullable();
            $table->json('beneficiary_list')->nullable();
            $table->json('custom_field_values')->nullable();
            $table->enum('status', ['draft', 'submitted', 'verified', 'changes_requested'])->default('draft');
            $table->text('reviewer_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_e_submissions');
    }
};
