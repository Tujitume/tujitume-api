<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing installations may have a legacy industry ID definition.
        // MySQL requires the foreign-key column's width and signedness to match.
        $industryIdDefinition = strtolower(Schema::getColumnType('program_industries', 'id', true));

        Schema::create('program_metadata', function (Blueprint $table) use ($industryIdDefinition) {
            $table->id();
            $industryIdColumn = match (true) {
                preg_match('/^bigint\\b/', $industryIdDefinition) === 1 => str_contains($industryIdDefinition, 'unsigned')
                    ? $table->unsignedBigInteger('program_industry_id')
                    : $table->bigInteger('program_industry_id'),
                preg_match('/^mediumint\\b/', $industryIdDefinition) === 1 => $table->mediumInteger('program_industry_id')->unsigned(str_contains($industryIdDefinition, 'unsigned')),
                preg_match('/^(?:int|integer)\\b/', $industryIdDefinition) === 1 => str_contains($industryIdDefinition, 'unsigned')
                    ? $table->unsignedInteger('program_industry_id')
                    : $table->integer('program_industry_id'),
                preg_match('/^smallint\\b/', $industryIdDefinition) === 1 => $table->smallInteger('program_industry_id')->unsigned(str_contains($industryIdDefinition, 'unsigned')),
                preg_match('/^tinyint\\b/', $industryIdDefinition) === 1 => $table->tinyInteger('program_industry_id')->unsigned(str_contains($industryIdDefinition, 'unsigned')),
                default => throw new RuntimeException("Unsupported program_industries.id type: {$industryIdDefinition}"),
            };
            $industryIdColumn->nullable();
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
