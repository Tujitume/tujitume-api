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
        Schema::create('round_custom_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('program_rounds')->onDelete('cascade');
            $table->string('question_text', 500);
            $table->enum('question_type', ['knockout','short_answer', 'long_text', 'multiple_choice', 'file_upload', 'budget_breakdown']);
            $table->json('options')->nullable(); // For multiple choice
            $table->boolean('is_required')->default(false);
            $table->integer('display_order');
            $table->string('knockout_fail_value', 45)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('round_custom_questions');
    }
};
