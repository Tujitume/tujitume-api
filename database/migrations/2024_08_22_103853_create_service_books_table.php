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
        Schema::create('service_books', function (Blueprint $table) {
            $table->id();
            $table->string('date', 155)->nullable();
            $table->integer('service_id')->nullable();
            $table->integer('booker_id')->nullable();
            $table->string('note', 800)->nullable();
            $table->integer('service_owner_id')->nullable();

            $table->enum('status', ['pending', 'confirmed', 'paid', 'in_progress', 'done'])
                ->default('pending');

            $table->string('stage', 100)->default('Pending');
            $table->integer('business_bid_id')->nullable();
            $table->tinyInteger('new')->nullable()->default(1);
            $table->tinyInteger('paid')->nullable();
            $table->string('method', 255)->nullable();
            $table->timestamps(); // created_at and updated_at, nullable by default
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_books');
    }
};
