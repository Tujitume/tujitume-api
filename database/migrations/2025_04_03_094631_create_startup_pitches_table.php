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
        Schema::create('startup_pitches', function (Blueprint $table) {
            $table->id();
            $table->integer('business_id')->nullable();
            $table->integer('capital_id');
            $table->integer('user_id');
            $table->integer('capital_owner_id');

            $table->string('startup_name', 255);
            $table->string('contact_person_name', 100);
            $table->string('contact_person_email', 100)->index();
            $table->string('sector', 255);
            $table->string('headquarters_location', 255);
            $table->string('stage', 100);
            $table->decimal('revenue_last_12_months', 15, 2)->default(0.00)->nullable();
            $table->integer('team_experience_avg_years')->nullable();
            $table->text('traction_kpis')->nullable();
            $table->string('pitch_deck_file', 255)->nullable();
            $table->string('pitch_video', 255)->nullable();
            $table->string('business_plan', 255)->nullable();
            $table->json('social_impact_areas')->nullable();
            $table->decimal('cac_ltv', 10, 2)->nullable();
            $table->decimal('burn_rate', 15, 2);
            $table->decimal('irr_projection', 5, 2)->nullable();
            $table->text('exit_strategy')->nullable();

            $table->tinyInteger('status')->default(0);
            $table->tinyInteger('terms_agreed')->default(0);
            $table->integer('score')->nullable();
            $table->string('score_breakdown', 200)->nullable();
            $table->float('total_amount_requested');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('startup_pitches');
    }
};
