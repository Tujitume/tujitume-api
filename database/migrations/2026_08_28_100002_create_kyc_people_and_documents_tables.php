<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_verification_id')->constrained()->cascadeOnDelete();
            $table->string('full_legal_name');
            $table->string('relationship_role', 30);
            $table->decimal('ownership_percentage', 5, 2)->nullable();
            $table->boolean('is_beneficial_owner')->default(false);
            $table->string('nationality', 2)->nullable();
            $table->string('id_type', 30)->nullable();
            $table->string('id_number', 100)->nullable();
            $table->boolean('requires_identity_verification')->default(false);
            $table->timestamps();
        });
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_verification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kyc_person_id')->nullable()->constrained('kyc_people')->cascadeOnDelete();
            $table->string('document_type', 60);
            $table->string('disk', 30);
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->timestamp('uploaded_at');
            $table->timestamps();
            $table->unique(['kyc_verification_id', 'document_type', 'kyc_person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
        Schema::dropIfExists('kyc_people');
    }
};
