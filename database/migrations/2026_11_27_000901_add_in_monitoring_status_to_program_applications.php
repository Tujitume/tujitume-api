<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision 27 Nov (replaces the first completion rule): an awarded application whose milestones are all finished is
 * 'in_monitoring' until every M&E checkpoint is verified (or the owner closes monitoring), then 'completed'. Adds
 * 'in_monitoring' to program_applications.status, and who closed monitoring and when.
 */
return new class extends Migration
{
    private const NEW = ['draft', 'pending', 'under_review', 'approved', 'rejected', 'waitlisted', 'awarded', 'in_monitoring', 'completed', 'withdrawn'];
    private const OLD = ['draft', 'pending', 'under_review', 'approved', 'rejected', 'waitlisted', 'awarded', 'completed', 'withdrawn'];

    public function up(): void
    {
        $this->allow(self::NEW);
        if (! Schema::hasColumn('program_applications', 'monitoring_closed_at')) {
            Schema::table('program_applications', function (Blueprint $table) {
                $table->timestamp('monitoring_closed_at')->nullable();
                $table->unsignedBigInteger('monitoring_closed_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('program_applications', 'monitoring_closed_at')) {
            Schema::table('program_applications', fn (Blueprint $table) => $table->dropColumn(['monitoring_closed_at', 'monitoring_closed_by']));
        }
        DB::table('program_applications')->where('status', 'in_monitoring')->update(['status' => 'awarded']);
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
