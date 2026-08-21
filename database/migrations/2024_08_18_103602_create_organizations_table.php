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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->onDelete('cascade');

            // ─── Identity ────────────────────────────────────────────────
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('legal_name')->nullable();
            $table->enum('organization_type', [
                'company',
                'ngo',
                'foundation',
                'government',
                'cooperative',
                'other'
            ])->default('company');
            $table->year('year_established')->nullable();
            $table->text('description')->nullable();

            // ─── Contact ─────────────────────────────────────────────────
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('website')->nullable();

            // ─── Location ────────────────────────────────────────────────
            $table->string('country', 10)->nullable();
            $table->string('region')->nullable();
            $table->string('city')->nullable();

            // ─── Industry ────────────────────────────────────────────────
            $table->foreignId('program_industry_id')
                ->nullable()->constrained('program_industries')
                ->nullOnDelete();

            $table->json('focus_sectors')->nullable();
            $table->json('operating_countries')->nullable();
            $table->json('target_regions')->nullable();

            // ─── Financial ───────────────────────────────────────────────
            $table->unsignedTinyInteger('financial_year_start_month')->default(1);

            // ─── Payment ─────────────────────────────────────────────────
            $table->string('lipr_wallet', 200)->nullable();
            $table->string('stripe_account_id')->nullable();

            // ─── Status ──────────────────────────────────────────────────
            $table->enum('status', [
                'pending_verification',
                'active',
                'suspended'
            ])->default('pending_verification');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
