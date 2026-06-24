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
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('mname')->nullable();
            $table->string('lname')->nullable();
            $table->string('fname')->nullable();
            $table->string('gender', 50)->default('Male');
            $table->string('dob', 100)->nullable();
            $table->unsignedTinyInteger('user_type_id')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('id_passport')->nullable();
            $table->string('pin')->nullable();
            $table->json('inv_range')->nullable();
            $table->json('turnover_range')->nullable();
            $table->json('interested_cats')->nullable();
            $table->string('past_investment', 1000)->nullable();
            $table->json('stage')->nullable();
            $table->json('social_impact_areas')->nullable();
            $table->json('regions_focus')->nullable();
            $table->string('website')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('id_no')->nullable();
            $table->string('tax_pin')->nullable();
            $table->string('connect_id')->nullable();
            $table->string('token')->nullable();
            $table->unsignedTinyInteger('completed_onboarding')->nullable();
            $table->string('lipr_wallet', 200)->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->string('image', 300)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
