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
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->string('name', 255)->nullable();
            $table->string('image', 300)->nullable();
            $table->integer('price')->nullable();
            $table->string('category', 155)->nullable();
            $table->json('business_sector_focus')->nullable();
            $table->string('details', 1000)->nullable();
            $table->string('location', 300)->nullable();
            $table->json('social_impact_areas')->nullable();
            $table->string('lat', 100)->nullable()->default('51.60526413029149');
            $table->string('lng', 100)->nullable()->default('-0.06537114999999996');
            $table->string('pin', 200)->nullable();
            $table->string('identification', 200)->nullable();
            $table->string('document', 200)->nullable();
            $table->string('video', 200)->nullable();
            $table->decimal('rating', 10, 2)->nullable();
            $table->integer('rating_count')->nullable();
            $table->unsignedTinyInteger('active')->nullable()->default(null);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
