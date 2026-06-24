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
        Schema::create('capital_milestones', function (Blueprint $table) {
            $table->increments('id'); // int(10), primary key, auto-increment
            $table->unsignedInteger('app_id')->nullable();
            $table->string('title', 500)->nullable();
            $table->unsignedInteger('amount')->nullable();
            $table->string('description', 1000)->nullable();
            $table->string('document', 300)->nullable();
            $table->tinyInteger('status')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capital_milestones');
    }
};
