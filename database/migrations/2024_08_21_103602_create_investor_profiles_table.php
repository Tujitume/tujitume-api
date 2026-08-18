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
        Schema::create('investor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade');

            $table->json('inv_range')->nullable();
            $table->json('turnover_range')->nullable();
            $table->json('interested_sectors')->nullable();
            $table->json('stage')->nullable();
            $table->json('social_impact_areas')->nullable();
            $table->json('regions_focus')->nullable();
            $table->string('past_investment', 1000)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investor_profiles');
    }
};
