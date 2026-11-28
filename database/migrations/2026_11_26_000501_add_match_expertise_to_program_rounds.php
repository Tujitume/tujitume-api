<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A round's owner can ask for applications to be shared out by expertise: with round robin or load balanced sharing,
 * each application then goes to a Business Coach whose expertise tags match the applicant's sector (falling back to
 * the usual rule when nobody matches). Off unless the owner turns it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('program_rounds', 'match_expertise')) return;
        Schema::table('program_rounds', function (Blueprint $table) {
            $table->boolean('match_expertise')->default(false)->after('assignment_method');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('program_rounds', 'match_expertise')) return;
        Schema::table('program_rounds', function (Blueprint $table) {
            $table->dropColumn('match_expertise');
        });
    }
};
