<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DECISION-9 for capital: before releasing a capital milestone the capital provider (owner or capital super admin) may
 * ask a project manager to audit it. The same fields as a service booking milestone's audit (2026_11_27_001101):
 * who audits, the audit's status, what was asked, the report (recommendation, notes, evidence files), and the fee the
 * project manager quoted with where that money is (held / released / refunded / release_failed / refund_failed).
 *
 * audit_status: pending (asked) | quoted (accepted with a fee, waiting for the provider to pay) | paying | accepted
 *               (work under way) | reported | declined | cancelled
 */
return new class extends Migration
{
    private const COLUMNS = [
        'auditor_id', 'audit_status', 'audit_request_notes', 'audit_requested_by', 'audit_requested_at', 'audit_recommendation',
        'audit_notes', 'audit_evidence', 'audit_completed_at', 'audit_fee', 'audit_fee_status', 'audit_fee_reference',
        'audit_fee_paid_by', 'audit_cancel_reason',
    ];

    public function up(): void
    {
        Schema::table('capital_milestones', function (Blueprint $table) {
            $add = [
                'auditor_id' => fn () => $table->unsignedBigInteger('auditor_id')->nullable(),
                'audit_status' => fn () => $table->string('audit_status', 20)->nullable(),
                'audit_request_notes' => fn () => $table->text('audit_request_notes')->nullable(),
                'audit_requested_by' => fn () => $table->unsignedBigInteger('audit_requested_by')->nullable(),
                'audit_requested_at' => fn () => $table->timestamp('audit_requested_at')->nullable(),
                'audit_recommendation' => fn () => $table->string('audit_recommendation', 20)->nullable(),
                'audit_notes' => fn () => $table->text('audit_notes')->nullable(),
                'audit_evidence' => fn () => $table->json('audit_evidence')->nullable(),
                'audit_completed_at' => fn () => $table->timestamp('audit_completed_at')->nullable(),
                'audit_fee' => fn () => $table->decimal('audit_fee', 12, 2)->nullable(),
                'audit_fee_status' => fn () => $table->string('audit_fee_status', 20)->nullable(),
                'audit_fee_reference' => fn () => $table->string('audit_fee_reference')->nullable(),
                'audit_fee_paid_by' => fn () => $table->unsignedBigInteger('audit_fee_paid_by')->nullable(),
                'audit_cancel_reason' => fn () => $table->text('audit_cancel_reason')->nullable(),
            ];
            foreach ($add as $column => $make) {
                if (! Schema::hasColumn('capital_milestones', $column)) {
                    $make();
                }
            }
        });
    }

    public function down(): void
    {
        $existing = array_values(array_filter(self::COLUMNS, fn ($c) => Schema::hasColumn('capital_milestones', $c)));
        if ($existing) {
            Schema::table('capital_milestones', fn (Blueprint $t) => $t->dropColumn($existing));
        }
    }
};
