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
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('listing_id')->nullable();
            $table->string('listing_name', 255)->nullable();
            $table->unsignedInteger('owner_id')->nullable();
            $table->unsignedTinyInteger('type')->nullable();
            $table->string('category', 150)->nullable();
            $table->string('details', 1000)->nullable();
            $table->string('document', 200)->nullable();
            $table->string('status', 100)->default('under_review');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
