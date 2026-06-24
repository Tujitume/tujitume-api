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
        Schema::create('milestone_communications', function (Blueprint $table) {
            $table->id();
            $table->text('message');
            $table->unsignedBigInteger('milestone_id');
            $table->unsignedBigInteger('sender_id');
            $table->string('sender_type')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();

            // Optional foreign key constraint
            $table->foreign('milestone_id')->references('id')->on('milestones')->onDelete('cascade');
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_communications');
    }
};
