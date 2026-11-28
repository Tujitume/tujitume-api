<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Programs and rounds open and close at an exact moment, not just on a day.
 *
 * The date columns stay (older screens and callers read them); the new *_at columns hold the exact moment in UTC and
 * are what every open/closed decision uses. programs.timezone is the zone the owner set the times in: the calendar
 * dates are that zone's dates, and a date given without a time is read in it.
 *
 * Existing rows keep exactly the moments the app enforced until now. Dates were read in the app's own zone (UTC):
 * open from 00:00:00 of the open date, closed after 23:59:59 of the close date. So those programs get the UTC zone and
 * those two moments, and nothing that is open today closes early. New programs default to Africa/Nairobi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            if (! Schema::hasColumn('programs', 'timezone')) {
                $table->string('timezone', 64)->default('Africa/Nairobi');
            }
            if (! Schema::hasColumn('programs', 'application_opens_at')) {
                $table->dateTime('application_opens_at')->nullable();
            }
            if (! Schema::hasColumn('programs', 'application_closes_at')) {
                $table->dateTime('application_closes_at')->nullable();
            }
        });

        Schema::table('program_rounds', function (Blueprint $table) {
            if (! Schema::hasColumn('program_rounds', 'opens_at')) {
                $table->dateTime('opens_at')->nullable();
            }
            if (! Schema::hasColumn('program_rounds', 'closes_at')) {
                $table->dateTime('closes_at')->nullable();
            }
        });

        $day = fn ($value) => $value ? substr((string) $value, 0, 10) : null;

        DB::table('programs')->whereNull('application_opens_at')->whereNull('application_closes_at')->orderBy('id')
            ->chunkById(500, function ($programs) use ($day) {
                foreach ($programs as $program) {
                    DB::table('programs')->where('id', $program->id)->update([
                        'timezone' => 'UTC',
                        'application_opens_at' => $day($program->application_start_date) ? $day($program->application_start_date) . ' 00:00:00' : null,
                        'application_closes_at' => $day($program->application_deadline) ? $day($program->application_deadline) . ' 23:59:59' : null,
                    ]);
                }
            });

        DB::table('program_rounds')->whereNull('opens_at')->whereNull('closes_at')->orderBy('id')
            ->chunkById(500, function ($rounds) use ($day) {
                foreach ($rounds as $round) {
                    DB::table('program_rounds')->where('id', $round->id)->update([
                        'opens_at' => $day($round->open_date) ? $day($round->open_date) . ' 00:00:00' : null,
                        'closes_at' => $day($round->close_date) ? $day($round->close_date) . ' 23:59:59' : null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('program_rounds', function (Blueprint $table) {
            foreach (['opens_at', 'closes_at'] as $column) {
                if (Schema::hasColumn('program_rounds', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('programs', function (Blueprint $table) {
            foreach (['timezone', 'application_opens_at', 'application_closes_at'] as $column) {
                if (Schema::hasColumn('programs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
