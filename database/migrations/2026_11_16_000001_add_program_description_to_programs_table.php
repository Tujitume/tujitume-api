<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('programs', 'program_description')) {
            return;
        }

        Schema::table('programs', function (Blueprint $table) {
            $table->string('program_description', 500)->nullable()->after('program_title');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('programs', 'program_description')) {
            return;
        }

        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn('program_description');
        });
    }
};
