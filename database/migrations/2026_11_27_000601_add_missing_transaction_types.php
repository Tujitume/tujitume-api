<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * e2e test 2026-11-27 (DECISION-5): the code records transactions of types the column did not allow, so those inserts
 * failed: every transfer from the main account to a program wallet (rolled back, 500), and the Business Coach payment
 * callback's records. Adds the types the code writes.
 */
return new class extends Migration
{
    private const OLD = ['deposit', 'withdraw', 'unlock_business', 'investment', 'investment_awaiting', 'service_fee', 'service_milestone',
        'program_milestone', 'program_milestone_bulk', 'capital_milestone', 'subscription', 'refund'];
    private const ADDED = ['transfer_to_program_wallet', 'reviewer_payment_sent', 'reviewer_payment_received', 'business_milestone', 'unlock_fee'];

    public function up(): void
    {
        $this->allow(array_merge(self::OLD, self::ADDED));
    }

    public function down(): void
    {
        DB::table('transactions')->whereIn('type', self::ADDED)->delete();
        $this->allow(self::OLD);
    }

    private function allow(array $values): void
    {
        $list = implode(',', array_map(fn ($value) => "'{$value}'", $values));
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY type ENUM({$list}) NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_type_check');
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_type_check CHECK (type IN ({$list}))");
        } elseif ($driver === 'sqlite') {
            $sql = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'transactions'")->sql;
            $rebuilt = preg_replace('/("type"\s+varchar[^,]*?check\s*\(\s*"type"\s+in\s*\()[^)]*(\))/i', '${1}'.$list.'${2}', $sql, 1, $count);
            if (! $count) return;
            $indexes = collect(DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'transactions' AND sql IS NOT NULL"))->pluck('sql');
            $rebuilt = preg_replace('/^CREATE TABLE\s+"?transactions"?/i', 'CREATE TABLE "transactions_rebuild"', $rebuilt);
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement($rebuilt);
            DB::statement('INSERT INTO transactions_rebuild SELECT * FROM transactions');
            DB::statement('DROP TABLE transactions');
            DB::statement('ALTER TABLE transactions_rebuild RENAME TO transactions');
            foreach ($indexes as $index) DB::statement($index);
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
