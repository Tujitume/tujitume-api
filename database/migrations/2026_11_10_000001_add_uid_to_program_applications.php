<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('program_applications', 'uid')) {
            Schema::table('program_applications', function (Blueprint $table) {
                $table->string('uid', 20)->nullable()->unique()->after('id');
            });
        }

        // Give every existing application its reference
        DB::table('program_applications')->whereNull('uid')->orderBy('id')->each(function ($row) {
            do {
                $uid = 'APP-' . strtoupper(Str::random(8));
            } while (DB::table('program_applications')->where('uid', $uid)->exists());
            DB::table('program_applications')->where('id', $row->id)->update(['uid' => $uid]);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('program_applications', 'uid')) {
            Schema::table('program_applications', function (Blueprint $table) {
                $table->dropUnique(['uid']);
                $table->dropColumn('uid');
            });
        }
    }
};
