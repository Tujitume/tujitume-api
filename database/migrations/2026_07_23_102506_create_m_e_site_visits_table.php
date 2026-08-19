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
        Schema::create('m_e_site_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkpoint_id')->constrained('m_e_checkpoints')->onDelete('cascade');
            $table->foreignId('app_id')->constrained('program_applications')->onDelete('cascade');
            $table->foreignId('reviewer_id')->constrained('users')->onDelete('cascade');
            $table->string('inspector')->nullable();
            $table->date('start_date')->nullable();
            $table->enum('assign_type', ['internal', 'external', 'third_party_audit'])->default('internal');
            $table->string('email')->nullable();
            $table->string('location')->nullable();
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->text('objective')->nullable();
            $table->json('kpi_targets')->nullable();        // [{kpi, target, actual}]
            $table->json('data_collection_fields')->nullable();
            $table->text('objectives_assessment')->nullable();
            $table->text('observed_actions')->nullable();
            $table->text('evidence_found')->nullable();
            $table->text('risk_notes')->nullable();
            $table->text('recommendation_notes')->nullable();
            $table->text('visit_comments')->nullable();
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('scheduled');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_e_site_visits');
    }
};
