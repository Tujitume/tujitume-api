<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Applicant emails a program owner can write (round.advanced, round.not_selected, application.accepted,
 * application.rejected): the owner may now also write the subject, and chooses the language the program's
 * emails go out in (programs.email_locale; null = English). See ProgramEmailDefaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_email_templates', function (Blueprint $table) {
            $table->string('subject', 200)->nullable()->after('event');
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->string('email_locale', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('program_email_templates', function (Blueprint $table) {
            $table->dropColumn('subject');
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn('email_locale');
        });
    }
};
