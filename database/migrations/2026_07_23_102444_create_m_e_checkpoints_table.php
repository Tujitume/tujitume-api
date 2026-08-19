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
        Schema::create('m_e_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('program_applications')->onDelete('cascade');
            $table->foreignId('program_id')->constrained('programs')->onDelete('cascade');
            $table->string('checkpoint_name');
            $table->enum('type', ['monitoring', 'reporting']);
            $table->date('due_date')->nullable();
            $table->text('requirement')->nullable();
            $table->text('checkpoint_description')->nullable();
            $table->boolean('require_site_visit')->default(false);
            $table->boolean('meeting_required')->default(false);
            $table->string('meeting_id')->nullable();
            $table->json('kpis_to_track')->nullable();
            $table->json('evidence_required')->nullable();
            $table->json('submission_fields')->nullable();
            $table->json('custom_submission_fields')->nullable();
            $table->enum('status', ['pending', 'submitted', 'verified', 'changes_requested'])->default('pending');
            $table->integer('display_order')->default(0);
            $table->boolean('should_notify_applicant')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_e_checkpoints');
    }
};
