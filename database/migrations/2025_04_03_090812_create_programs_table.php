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
        Schema::create('programs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('program_industry_id')->nullable();

            $table->string('program_title', 255);
            $table->decimal('total_program_amount', 15, 2);
            $table->decimal('available_amount', 15, 2)->nullable();
            $table->decimal('funding_per_business', 15, 2);

            $table->enum('disbursement_type', [
                'supplier',
                'hybrid',
                'beneficiary',
            ])->default('supplier');

            $table->date('application_start_date')->nullable();
            $table->date('application_deadline')->nullable();
            $table->boolean('require_kyc_verification_to_apply')->default(false);

            $table->enum('status', [
                'draft',
                'published',
                'open',
                'closed',
                'under_review',
                'awarded',
                'cancelled',
                'completed',
            ])->default('draft');

            $table->boolean('visible')->default(true);
            $table->enum('currency', ['KES', 'USD'])->default('KES');
            $table->enum('program_type', ['single_round', 'multi_round'])->default('single_round');
            $table->integer('total_rounds')->default(1);
            $table->integer('max_awardees')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('restrict');
            $table->foreign('program_industry_id')->references('id')->on('program_industries')->nullOnDelete();

            $table->timestamps();
            $table->unsignedTinyInteger('match_score_limit')->nullable();
            $table->ulid('public_id')->nullable()->default(null)->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};
