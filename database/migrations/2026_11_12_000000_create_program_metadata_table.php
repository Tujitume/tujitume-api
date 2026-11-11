<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This file was renamed (2027_11_05_... -> 2026_11_12_...). A database that ran it under the old name must not run it again.
        if (Schema::hasTable('program_metadata')) {
            return;
        }

        Schema::create('program_metadata', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();

            $table->foreignId('program_industry_id')
                ->nullable()
                ->constrained('program_industries')
                ->nullOnDelete();

            $table->string('key');
            $table->json('value');
            $table->timestamps();

            $table->unsignedBigInteger('program_industry_scope')
                ->storedAs('COALESCE(program_industry_id, 0)');

            $table->unique(
                ['program_industry_scope', 'key'],
                'program_metadata_scope_key_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_metadata');
    }
};
