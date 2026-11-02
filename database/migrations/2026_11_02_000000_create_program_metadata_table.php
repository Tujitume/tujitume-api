<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing installations may have created this legacy table with an
        // integer ID instead of the bigint defined by the current migration.
        // MySQL requires the FK column width and signedness to match exactly.
        $industryIdType = strtolower(Schema::getColumnType('program_industries', 'id'));

        Schema::create('program_metadata', function (Blueprint $table) use ($industryIdType) {
            $table->id();
            if (in_array($industryIdType, ['integer', 'int'], true)) {
                $table->unsignedInteger('program_industry_id')->nullable();
            } else {
                $table->unsignedBigInteger('program_industry_id')->nullable();
            }
            $table->foreign('program_industry_id')
                ->references('id')
                ->on('program_industries')
                ->nullOnDelete();
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
