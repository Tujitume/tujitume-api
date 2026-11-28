<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DECISION-3 (owner, 27 Nov): an awarded application becomes 'completed' once its last milestone's final approval is
 * approved. Adds 'completed' to program_applications.status.
 */
return new class extends Migration
{
    private const NEW = ['draft', 'pending', 'under_review', 'approved', 'rejected', 'waitlisted', 'awarded', 'completed', 'withdrawn'];
    private const OLD = ['draft', 'pending', 'under_review', 'approved', 'rejected', 'waitlisted', 'awarded', 'withdrawn'];

    public function up(): void
    {
        $this->allow(self::NEW);
    }

    public function down(): void
    {
        DB::table('program_applications')->where('status', 'completed')->update(['status' => 'awarded']);
        $this->allow(self::OLD);
    }

    private function allow(array $values): void
    {
        $list = implode(',', array_map(fn ($value) => "'{$value}'", $values));
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE program_applications MODIFY status ENUM({$list}) NOT NULL DEFAULT 'draft'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE program_applications DROP CONSTRAINT IF EXISTS program_applications_status_check');
            DB::statement("ALTER TABLE program_applications ADD CONSTRAINT program_applications_status_check CHECK (status IN ({$list}))");
        } elseif ($driver === 'sqlite') {
            // SQLite keeps the enum as a CHECK constraint: rebuild the table with the new list, keeping its indexes
            $sql = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'program_applications'")->sql;
            $rebuilt = preg_replace('/("status"\s+varchar[^,]*?check\s*\(\s*"status"\s+in\s*\()[^)]*(\))/i', '${1}'.$list.'${2}', $sql, 1, $count);
            if (! $count) return;
            $indexes = collect(DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'program_applications' AND sql IS NOT NULL"))->pluck('sql');
            $rebuilt = preg_replace('/^CREATE TABLE\s+"?program_applications"?/i', 'CREATE TABLE "program_applications_rebuild"', $rebuilt);
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement($rebuilt);
            DB::statement('INSERT INTO program_applications_rebuild SELECT * FROM program_applications');
            DB::statement('DROP TABLE program_applications');
            DB::statement('ALTER TABLE program_applications_rebuild RENAME TO program_applications');
            foreach ($indexes as $index) DB::statement($index);
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
