<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Service bookings keep their history: a booking the provider rejects, or the customer cancels before paying, is kept
 * with the status 'rejected' or 'cancelled' (it used to be deleted), so the provider's All tab and the customer's list
 * show it. Adds those two values to service_bookings.status.
 */
return new class extends Migration
{
    private const NEW = ['pending', 'confirmed', 'paid', 'in_progress', 'done', 'completed', 'rejected', 'cancelled'];
    private const OLD = ['pending', 'confirmed', 'paid', 'in_progress', 'done', 'completed'];

    public function up(): void
    {
        $this->allow(self::NEW);
    }

    public function down(): void
    {
        // The old list cannot hold the kept rows: they go, as they did before
        DB::table('service_bookings')->whereIn('status', ['rejected', 'cancelled'])->delete();
        $this->allow(self::OLD);
    }

    private function allow(array $values): void
    {
        $list = implode(',', array_map(fn ($value) => "'{$value}'", $values));
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE service_bookings MODIFY status ENUM({$list}) NOT NULL DEFAULT 'pending'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE service_bookings DROP CONSTRAINT IF EXISTS service_bookings_status_check');
            DB::statement("ALTER TABLE service_bookings ADD CONSTRAINT service_bookings_status_check CHECK (status IN ({$list}))");
        } elseif ($driver === 'sqlite') {
            // SQLite keeps the enum as a CHECK constraint: rebuild the table from its own definition with the new list
            $sql = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'service_bookings'")->sql;
            $rebuilt = preg_replace('/("status"\s+varchar[^,]*?check\s*\(\s*"status"\s+in\s*\()[^)]*(\))/i', '${1}'.$list.'${2}', $sql, 1, $count);
            if (! $count) return; // no constraint on this database: nothing to change
            $rebuilt = preg_replace('/^CREATE TABLE\s+"?service_bookings"?/i', 'CREATE TABLE "service_bookings_rebuild"', $rebuilt);
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement($rebuilt);
            DB::statement('INSERT INTO service_bookings_rebuild SELECT * FROM service_bookings');
            DB::statement('DROP TABLE service_bookings');
            DB::statement('ALTER TABLE service_bookings_rebuild RENAME TO service_bookings');
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
