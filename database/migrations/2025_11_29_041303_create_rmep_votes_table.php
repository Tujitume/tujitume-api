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
        Schema::create('rmep_votes', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('rmep_id'); // references milestone_rmeps.id
            $table->unsignedBigInteger('investor_id'); // references users.id

            $table->enum('vote', ['approve', 'reject']);

            // Weighted voting (based on investment %)
            $table->decimal('weight', 10, 2)->default(0); // example: 12.50%

            $table->timestamp('voted_at')->nullable();
            $table->text('comment')->nullable();
            $table->foreign('rmep_id')->references('id')->on('milestone_rmeps')->onDelete('cascade');

            $table->timestamps();
            $table->unique(['rmep_id', 'investor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rmep_votes');
    }
};
