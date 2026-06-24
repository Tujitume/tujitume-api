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
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');     // int(10), signed
            $table->string('timezone', 150)->nullable();            // NOT NULL
            $table->string('day', 150);                  // NOT NULL
            $table->string('start_hour', 150);          // NOT NULL
            $table->string('end_hour', 150);            // NOT NULL
            $table->timestamps(); // created_at & updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
