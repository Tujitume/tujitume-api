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
        Schema::create('nc_documents', function (Blueprint $table) {
            $table->id();

            // Link to NC
            $table->unsignedBigInteger('nc_id');
            $table->foreign('nc_id')->references('id')->on('milestone_non_compliances')->onDelete('cascade');

            $table->string('file_path'); // storage path
            $table->string('file_type'); // [ image, video, pdf, doc, etc ]
            $table->enum('response_type', ['rmep', 'completion_proof', 'other'])->default('other'); // document type
            $table->unsignedBigInteger('uploaded_by'); // user_id of owner/uploader

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nc_documents');
    }
};
