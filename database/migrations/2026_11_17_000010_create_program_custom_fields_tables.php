<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_custom_fields', function (Blueprint $t) {
            $t->id();
            $t->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $t->string('key', 64);
            $t->string('label', 150);
            $t->text('help_text')->nullable();
            $t->enum('audience', ['application', 'info'])->default('application');
            $t->enum('type', ['text', 'textarea', 'number', 'boolean', 'date', 'select', 'multi_select', 'file', 'url']);
            $t->boolean('is_required')->default(false);
            $t->json('options')->nullable();
            $t->json('validation')->nullable();
            $t->boolean('is_scoring')->default(false);
            $t->json('scoring')->nullable();            // {enabled, weight, match_type, target, mode}
            $t->json('scoring_suggestion')->nullable(); // imported suggestions, never active until the owner enables them
            $t->boolean('is_bonus')->default(false);
            $t->boolean('is_public')->default(true);
            $t->boolean('is_archived')->default(false);
            $t->unsignedInteger('display_order')->default(0);
            $t->timestamps();
            $t->unique(['program_id', 'key']);
            $t->index(['program_id', 'audience', 'is_archived']);
        });

        Schema::create('program_custom_field_values', function (Blueprint $t) {
            $t->id();
            $t->foreignId('field_id')->constrained('program_custom_fields')->cascadeOnDelete();
            $t->enum('entity_type', ['program', 'application']);
            $t->unsignedBigInteger('entity_id');
            $t->text('value_text')->nullable();
            $t->decimal('value_number', 20, 4)->nullable();
            $t->date('value_date')->nullable();
            $t->boolean('value_bool')->nullable();
            $t->json('value_json')->nullable();
            $t->timestamps();
            $t->unique(['field_id', 'entity_type', 'entity_id'], 'pcfv_unique');
            $t->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_custom_field_values');
        Schema::dropIfExists('program_custom_fields');
    }
};
