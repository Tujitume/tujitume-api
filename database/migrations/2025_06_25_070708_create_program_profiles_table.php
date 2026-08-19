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
        Schema::create('program_profiles', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->integer('role_id')->nullable()->default(null);
            $table->integer('program_owner_id')->nullable()->default(null);
            $table->string('org_type','255')->nullable()->default(null);
            $table->json('regions')->nullable()->default(null);
            $table->string('mission', 500)->nullable()->default(null);
            $table->string('document', '100')->nullable()->default(null);
            $table->tinyInteger('active')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('program_profiles');
    }
};
