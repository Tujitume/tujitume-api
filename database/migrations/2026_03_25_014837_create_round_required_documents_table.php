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
        Schema::create('round_required_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('program_applications')->onDelete('cascade');
            $table->foreignId('round_id')->constrained('program_rounds')->onDelete('cascade');
            $table->string('document_type', 100); // e.g., 'business_registration', 'financial_statements', etc.
            $table->string('file_path'); // Path to uploaded file
            $table->string('original_filename')->nullable(); // Original file name for download
            $table->bigInteger('file_size')->nullable(); // File size in bytes
            $table->string('mime_type', 100)->nullable(); // e.g., 'application/pdf'
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->text('verification_notes')->nullable(); // Notes from program owner/reviewer
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();


            // Indexes for faster queries
            $table->index(['application_id', 'round_id']);
            $table->index('verification_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('round_required_documents');
    }
};
