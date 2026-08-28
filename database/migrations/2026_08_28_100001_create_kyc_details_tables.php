<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entrepreneur_kyc_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_verification_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('legal_name')->nullable();
            $table->string('id_type', 30)->nullable();
            $table->string('id_number', 100)->nullable();
            $table->string('id_issuing_country', 2)->nullable();
            $table->date('id_expiry_date')->nullable();
            $table->string('nationality', 2)->nullable();
            $table->string('physical_address')->nullable();
            $table->string('county_region')->nullable();
            $table->string('tax_pin', 100)->nullable();
            $table->boolean('is_registered_business')->default(false);
            $table->string('business_legal_name')->nullable();
            $table->string('business_registration_number', 100)->nullable();
            $table->string('registration_country', 2)->nullable();
            $table->string('legal_structure', 30)->nullable();
            $table->timestamps();
        });
        Schema::create('service_provider_kyc_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_verification_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('legal_name')->nullable();
            $table->string('id_type', 30)->nullable();
            $table->string('id_number', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('physical_address')->nullable();
            $table->string('tax_pin', 100)->nullable();
            $table->boolean('operates_through_business')->default(false);
            $table->string('business_legal_name')->nullable();
            $table->string('business_type', 30)->nullable();
            $table->string('business_registration_number', 100)->nullable();
            $table->boolean('requires_professional_licence')->default(false);
            $table->timestamps();
        });
        Schema::create('organization_kyc_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_verification_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('legal_name')->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->string('registration_country', 2)->nullable();
            $table->string('legal_structure', 30)->nullable();
            $table->string('tax_pin', 100)->nullable();
            $table->string('physical_address')->nullable();
            $table->string('county_region')->nullable();
            $table->string('representative_full_legal_name')->nullable();
            $table->string('representative_role_title')->nullable();
            $table->string('representative_id_type', 30)->nullable();
            $table->string('representative_id_number', 100)->nullable();
            $table->string('representative_phone', 50)->nullable();
            $table->string('representative_email')->nullable();
            $table->boolean('authorization_confirmation')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_kyc_details');
        Schema::dropIfExists('service_provider_kyc_details');
        Schema::dropIfExists('entrepreneur_kyc_details');
    }
};
