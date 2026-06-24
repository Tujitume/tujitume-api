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
        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type')->nullable(); // e.g. 'Exception', 'Validation', 'Mail', etc.
            $table->string('file')->nullable();
            $table->integer('line')->nullable();
            $table->text('message');
            $table->longText('trace')->nullable();
            $table->text('url')->nullable();
            $table->json('context')->nullable(); // Request info, payload, etc.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('error_logs');
    }
};
