<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_industry_id')->nullable()->constrained('program_industries')->nullOnDelete();
            $table->string('key');
            $table->json('value');
            $table->timestamps();
            // Coalescing NULL to 0 makes the global-default scope unique on MySQL as well as SQLite.
            $table->unsignedBigInteger('program_industry_scope')->storedAs('COALESCE(program_industry_id, 0)');
            $table->unique(['program_industry_scope', 'key'], 'program_metadata_scope_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_metadata');
    }
};
