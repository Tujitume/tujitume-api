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
        Schema::create('m_e_submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('m_e_submissions')->onDelete('cascade');
            $table->enum('file_type', ['document', 'photo_video', 'beneficiary_list', 'other']);
            $table->string('file_path');
            $table->string('original_filename')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_e_submission_files');
    }
};
