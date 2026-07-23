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
        Schema::create('m_e_site_visit_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_visit_id')->constrained('m_e_site_visits')->onDelete('cascade');
            $table->string('file_path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_e_site_visit_files');
    }
};
