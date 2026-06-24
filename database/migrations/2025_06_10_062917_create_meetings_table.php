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
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->integer('host_id');
            $table->integer('client_id');
            $table->string('client_name',300)->nullable();
            $table->string('host_name',300)->nullable();
            $table->string('date',100);
            $table->string('time',100);
            $table->string('title',255)->nullable();
            $table->string('description',1000)->nullable();
            $table->string('link',200);
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
