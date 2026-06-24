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
        Schema::create('disputes', function (Blueprint $table) {
            $table->id(); // int unsigned, auto-increment primary key
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('mile_id')->nullable();
            $table->string('mile_name', 255)->nullable();
            $table->string('project_name', 255)->nullable();
            $table->string('reason', 300)->nullable();
            $table->string('details', 1000)->nullable();
            $table->string('type', 2)->nullable();
            $table->string('document', 200)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
