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
        Schema::create('program_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('label', 150);
            $table->text('help_text')->nullable();
            $table->enum('audience', ['application', 'info'])->default('application');
            $table->enum('type', ['text', 'textarea', 'number', 'boolean', 'date', 'select', 'multi_select', 'file', 'url'])->default('text');
            $table->boolean('is_required')->default(false);
            $table->json('options')->nullable();
            $table->json('validation')->nullable();
            $table->boolean('is_scoring')->default(false);
            $table->json('scoring')->nullable();
            $table->boolean('is_bonus')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            $table->enum('visibility', ['public', 'owner_only'])->default('public');
            $table->boolean('is_archived')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['program_id', 'key']);
            $table->index(['program_id', 'audience', 'is_archived', 'display_order']);
        });

        Schema::create('program_custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_id')->constrained('program_custom_fields')->cascadeOnDelete();
            $table->enum('entity_type', ['program', 'application']);
            $table->unsignedBigInteger('entity_id');
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 4)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_bool')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->unique(['field_id', 'entity_type', 'entity_id']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('program_custom_field_values');
        Schema::dropIfExists('program_custom_fields');
    }
};
