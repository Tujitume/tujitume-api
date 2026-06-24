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
        Schema::create('service_mile_statuses', function (Blueprint $table) {
            $table->id();
            $table->integer('mile_id')->nullable();
            $table->integer('service_id')->nullable();
            $table->integer('booker_id')->nullable();
            $table->integer('booking_id')->nullable();
            $table->string('title', 300)->nullable();
            $table->integer('amount')->nullable();
            $table->string('document', 200)->nullable();
            $table->tinyInteger('active')->nullable();
            $table->string('status', 100)->default('To Do');
            $table->tinyInteger('released')->nullable();
            $table->integer('n_o_days', false, true)->nullable(); // int(5) unsigned
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_mile_statuses');
    }
};
