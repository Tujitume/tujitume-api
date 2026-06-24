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
        Schema::create('service_messages', function (Blueprint $table) {
            $table->id();
            $table->integer('service_id')->nullable();
            $table->integer('booker_id')->nullable();
            $table->integer('service_owner_id')->nullable();
            $table->string('msg', 2000)->nullable();
            $table->integer('to_id')->nullable();
            $table->integer('from_id')->nullable();
            $table->timestamps();  // created_at & updated_at, nullable by default
            $table->tinyInteger('new')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_messages');
    }
};
