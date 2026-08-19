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
        Schema::create('application_round_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('program_applications')->onDelete('cascade');
            $table->foreignId('round_id')->constrained('program_rounds')->onDelete('cascade');
            $table->foreignId('question_id')->constrained('round_custom_questions')->onDelete('cascade');
            $table->text('response')->nullable();
            $table->string('file_path')->nullable(); // For file uploads
            $table->timestamps();

            $table->unique(['application_id', 'question_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_round_responses');
    }
};
