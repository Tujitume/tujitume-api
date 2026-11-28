<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What step 5 will create, program by program, in the form the app saves (built from the checked import when
 * the person presses Start). The import job works through it; it is kept so a stopped import can carry on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_imports', function (Blueprint $table) {
            $table->json('plan')->nullable()->after('data');
        });
    }

    public function down(): void
    {
        Schema::table('program_imports', function (Blueprint $table) {
            $table->dropColumn('plan');
        });
    }
};
