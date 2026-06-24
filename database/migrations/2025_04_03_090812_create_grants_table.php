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
        Schema::create('grants', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->nullable();          // grant admin / owner

            // Core grant info
            $table->string('grant_title', 255);
            $table->decimal('total_grant_amount', 15, 2);
            $table->decimal('available_amount', 15, 2)->nullable();     // was float → decimal
            $table->decimal('funding_per_business', 15, 2);

            // Funder info (new)
            $table->string('funder_name', 200)->nullable();
            $table->enum('funder_type', [
                'government',
                'ngo',
                'private',
                'development',
                'international_aid',
                'other',
            ])->default('government');

            $table->enum('disbursement_type', [
                'supplier',
                'hybrid',
                'beneficiary',
            ])->default('supplier');

            // Criteria & targeting
            $table->text('eligibility_criteria')->nullable();
            $table->json('required_documents')->nullable();              // was string(100) → json
            $table->json('grant_focus')->nullable();
            $table->json('regions')->nullable();
            $table->json('startup_stage_focus')->nullable();

            // Objectives
            $table->text('impact_objectives')->nullable();
            $table->json('social_impact_areas')->nullable();
            $table->json('bonus_points')->nullable();

            $table->text('evaluation_criteria')->nullable();

            $table->boolean('mid_milestone_required')->default(false);

            // Dates — fixed to proper DATE type (were all VARCHAR/string)
            $table->date('application_deadline')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            // Files
            $table->string('grant_brief_pdf', 255)->nullable();

            // Status — ENUM replaces tinyInt (readable, safe)
            $table->enum('status', [
                'draft',            // admin setting up
                'published',
                'open',             // accepting applications
                'closed',           // no more applications
                'under_review',     // applications being evaluated
                'awarded',          // winners selected & funds allocated
                'cancelled',
                'completed',
            ])->default('draft');

            $table->tinyInteger('visible')->default(1);
            $table->char('currency', 3)->default('USD');

            $table->enum('grant_type', ['single_round', 'multi_round'])->default('single_round');
            $table->integer('total_rounds')->default(1);// new: multi-currency ready
            $table->integer('max_awardees')->nullable(); // The max awardees validation we just discussed!


            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grants');
    }
};
