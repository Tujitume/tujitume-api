<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('program_applications', 'email')) {
            Schema::table('program_applications', function (Blueprint $table) {
                $table->string('email', 100)->nullable()->after('contact_person_email');
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE program_applications MODIFY user_id BIGINT UNSIGNED NULL');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE program_applications ALTER COLUMN user_id DROP NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE program_applications MODIFY user_id BIGINT UNSIGNED NOT NULL');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE program_applications ALTER COLUMN user_id SET NOT NULL');
        }

        if (Schema::hasColumn('program_applications', 'email')) {
            Schema::table('program_applications', fn (Blueprint $table) => $table->dropColumn('email'));
        }
    }
};
