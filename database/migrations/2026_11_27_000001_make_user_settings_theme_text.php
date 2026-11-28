<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * user_settings.theme was first created as an ENUM of five themes ('default','ocean','forest','sunset','minimal').
 * The create migration was later changed to a string, but that does not change a database that already ran it, so
 * newer themes ("tujitume", the logo green, and the rest of UserSetting::THEME_KEYS) were refused there.
 * This makes the column VARCHAR(30) wherever it is still an enum (MySQL only; doctrine/dbal is not installed).
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasColumn('user_settings', 'theme')) return;
        $type = DB::selectOne("SELECT DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_settings' AND COLUMN_NAME = 'theme'")?->type;
        if (strtolower((string) $type) !== 'enum') return;
        DB::statement("ALTER TABLE user_settings MODIFY theme VARCHAR(30) NOT NULL DEFAULT 'default'");
    }

    public function down(): void
    {
        // Left as text: going back to the enum would fail for anyone using a newer theme
    }
};
