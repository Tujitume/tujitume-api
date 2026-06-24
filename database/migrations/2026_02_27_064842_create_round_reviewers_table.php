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
        Schema::create('round_reviewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('grant_rounds')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('reviewer_type', ['internal', 'external'])->default('internal');
            $table->integer('max_apps_assigned')->nullable(); // For load balancing
            $table->json('expertise_tags')->nullable(); // ['agri', 'tech', 'energy']
            $table->timestamps();

            $table->unique(['round_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('round_reviewers');
    }
};
