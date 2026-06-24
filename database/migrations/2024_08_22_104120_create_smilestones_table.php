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
        Schema::create('smilestones', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->integer('listing_id');
            $table->string('title', 255)->nullable();
            $table->integer('amount')->nullable();
            $table->string('document', 200)->nullable();
            $table->integer('n_o_days')->nullable();
            $table->string('status', 100)->default('Created')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smilestones');
    }
};
