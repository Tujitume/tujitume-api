<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DECISION-9 (paid project manager audits) and the DECISION-8 change (the owner may skip or cancel an audit).
 *  - milestone_verifications: the fee the project manager quoted, where that money is (held / released / refunded /
 *    release_failed), the transfer reference, and why and by whom an audit was skipped or cancelled.
 *  - service_booking_milestones: the optional audit a customer asks for on a "Done" milestone (same fields as a program
 *    check's audit, plus where the fee is).
 *  - p_m_audits (business): the fee quoted when the project manager accepts (recorded; investors' payment not built yet).
 *  - transactions.type: pm_audit_fee (the payer's debit and the project manager's credit).
 */
return new class extends Migration
{
    private const TYPES = ['deposit', 'withdraw', 'unlock_business', 'investment', 'investment_awaiting', 'service_fee', 'service_milestone',
        'program_milestone', 'program_milestone_bulk', 'capital_milestone', 'subscription', 'refund',
        'transfer_to_program_wallet', 'reviewer_payment_sent', 'reviewer_payment_received', 'business_milestone', 'unlock_fee'];

    public function up(): void
    {
        Schema::table('milestone_verifications', function (Blueprint $table) {
            if (! Schema::hasColumn('milestone_verifications', 'audit_fee')) $table->decimal('audit_fee', 12, 2)->nullable();
            if (! Schema::hasColumn('milestone_verifications', 'audit_fee_status')) $table->string('audit_fee_status', 20)->nullable();
            if (! Schema::hasColumn('milestone_verifications', 'audit_fee_reference')) $table->string('audit_fee_reference')->nullable();
            if (! Schema::hasColumn('milestone_verifications', 'audit_fee_paid_by')) $table->unsignedBigInteger('audit_fee_paid_by')->nullable();
            if (! Schema::hasColumn('milestone_verifications', 'audit_skip_reason')) $table->text('audit_skip_reason')->nullable();
            if (! Schema::hasColumn('milestone_verifications', 'audit_skipped_by')) $table->unsignedBigInteger('audit_skipped_by')->nullable();
            if (! Schema::hasColumn('milestone_verifications', 'audit_skipped_at')) $table->timestamp('audit_skipped_at')->nullable();
        });

        Schema::table('service_booking_milestones', function (Blueprint $table) {
            if (! Schema::hasColumn('service_booking_milestones', 'auditor_id')) $table->unsignedBigInteger('auditor_id')->nullable();
            if (! Schema::hasColumn('service_booking_milestones', 'audit_status')) $table->string('audit_status', 20)->nullable();
            if (! Schema::hasColumn('service_booking_milestones', 'audit_request_notes')) $table->text('audit_request_notes')->nullable();
            if (! Schema::hasColumn('service_booking_milestones', 'audit_recommendation')) $table->string('audit_recommendation', 20)->nullable();
            if (! Schema::hasColumn('service_booking_milestones', 'audit_notes')) $table->text('audit_notes')->nullable();
            if (! Schema::hasColumn('service_booking_milestones', 'audit_requested_at')) $table->timestamp('audit_requested_at')->nullable();
            if (! Schema::hasColumn('service_booking_milestones', 'audit_completed_at')) $table->timestamp('audit_completed_at')->nullable();
            if (! Schema::hasColumn('service_booking_milestones', 'audit_fee')) $table->decimal('audit_fee', 12, 2)->nullable();
            if (! Schema::hasColumn('service_booking_milestones', 'audit_fee_status')) $table->string('audit_fee_status', 20)->nullable();
            if (! Schema::hasColumn('service_booking_milestones', 'audit_fee_reference')) $table->string('audit_fee_reference')->nullable();
        });

        Schema::table('p_m_audits', function (Blueprint $table) {
            if (! Schema::hasColumn('p_m_audits', 'fee')) $table->decimal('fee', 12, 2)->nullable();
            if (! Schema::hasColumn('p_m_audits', 'fee_status')) $table->string('fee_status', 20)->nullable();
        });

        $this->allowTypes([...self::TYPES, 'pm_audit_fee']);
    }

    public function down(): void
    {
        DB::table('transactions')->where('type', 'pm_audit_fee')->delete();
        $this->allowTypes(self::TYPES);
        Schema::table('milestone_verifications', fn (Blueprint $t) => $t->dropColumn(['audit_fee', 'audit_fee_status', 'audit_fee_reference', 'audit_fee_paid_by', 'audit_skip_reason', 'audit_skipped_by', 'audit_skipped_at']));
        Schema::table('service_booking_milestones', fn (Blueprint $t) => $t->dropColumn(['auditor_id', 'audit_status', 'audit_request_notes', 'audit_recommendation', 'audit_notes', 'audit_requested_at', 'audit_completed_at', 'audit_fee', 'audit_fee_status', 'audit_fee_reference']));
        Schema::table('p_m_audits', fn (Blueprint $t) => $t->dropColumn(['fee', 'fee_status']));
    }

    /** Same as 2026_11_27_000601: the type column is an ENUM (MySQL) / CHECK (SQLite, Postgres). */
    private function allowTypes(array $values): void
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
