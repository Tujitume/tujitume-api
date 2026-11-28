<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Program imports: one row per import an organization starts on Programs → Imports. It keeps the choices
 * made so far and the step the person is on, so the import can be continued later or after a reload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who started it
            $table->string('mode', 20);                                                // new | existing
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('program_industry_id')->nullable()->constrained('program_industries')->nullOnDelete();
            $table->string('stage', 30);                                               // the stage chosen in step 1
            $table->string('current_stage', 30)->nullable();                          // where an existing program was
            $table->string('step', 30)->default('upload');                           // the step the person is on
            $table->string('file_path')->nullable();                                   // private, on the local disk
            $table->string('file_name')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('status', 20)->default('draft');                           // draft | completed
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_imports');
    }
};
