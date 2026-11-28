<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Meetings were saved as a bare date and clock time, so a 07:00 meeting read as 07:00 for everyone wherever they were.
 * Each meeting now keeps the zone its time was chosen in (`timezone`) and the exact moment it starts (`starts_at`,
 * UTC), so every invitee sees it in their own time. Existing meetings are read in the host's Settings time zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            if (! Schema::hasColumn('meetings', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('time');
            }
            if (! Schema::hasColumn('meetings', 'starts_at')) {
                $table->dateTime('starts_at')->nullable()->after('timezone')->index();
            }
        });

        $zones = DB::table('user_settings')->pluck('timezone', 'user_id');
        $valid = array_flip(\DateTimeZone::listIdentifiers());

        DB::table('meetings')->whereNull('starts_at')->orderBy('id')->chunkById(500, function ($meetings) use ($zones, $valid) {
            foreach ($meetings as $meeting) {
                $zone = $zones[$meeting->host_id] ?? null;
                $zone = $zone && isset($valid[$zone]) ? $zone : 'Africa/Nairobi';
                try {
                    $startsAt = Carbon::parse(substr((string) $meeting->date, 0, 10) . ' ' . substr((string) $meeting->time, 0, 5), $zone)->utc();
                } catch (\Throwable) {
                    $startsAt = null;
                }
                DB::table('meetings')->where('id', $meeting->id)->update(['timezone' => $zone, 'starts_at' => $startsAt?->toDateTimeString()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            if (Schema::hasColumn('meetings', 'starts_at')) {
                $table->dropIndex(['starts_at']);
                $table->dropColumn('starts_at');
            }
            if (Schema::hasColumn('meetings', 'timezone')) {
                $table->dropColumn('timezone');
            }
        });
    }
};
