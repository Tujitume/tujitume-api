<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A file-upload question's file categories get their own column instead of living inside `options`.
 * Existing questions are not rewritten: while the column is empty the categories are still read from `options`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('round_custom_questions', 'file_category')) {
            Schema::table('round_custom_questions', function (Blueprint $table) {
                $table->json('file_category')->nullable()->after('options');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('round_custom_questions', 'file_category')) {
            Schema::table('round_custom_questions', fn (Blueprint $table) => $table->dropColumn('file_category'));
        }
    }
};
