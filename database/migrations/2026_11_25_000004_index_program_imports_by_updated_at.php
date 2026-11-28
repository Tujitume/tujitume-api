<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The Imports list is the organization's imports, newest first: an index lets the database read them in that order. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_imports', function (Blueprint $table) {
            $table->index(['organization_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('program_imports', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'updated_at']);
        });
    }
};
