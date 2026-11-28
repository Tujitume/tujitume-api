<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project Manager Audits (App\Service\Audits\ProjectManagerAudits): one place for every audit a project manager is
 * asked to do, business and program.
 * - p_m_audits (business): the PM's decision (approved / rejected), when, and their notes. The review released funds
 *   but never recorded its own outcome, so the audit stayed "accepted".
 * - milestone_verifications (program): where the auditor is with an audit the program owner requested (pending,
 *   accepted, declined, reported) and their recommendation (approve / reject); audit_notes and audit_completed_at
 *   already hold the report text and time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('p_m_audits', function (Blueprint $table) {
            if (! Schema::hasColumn('p_m_audits', 'decision')) $table->string('decision', 20)->nullable();
            if (! Schema::hasColumn('p_m_audits', 'decided_at')) $table->timestamp('decided_at')->nullable();
            if (! Schema::hasColumn('p_m_audits', 'notes')) $table->text('notes')->nullable();
        });
        Schema::table('milestone_verifications', function (Blueprint $table) {
            if (! Schema::hasColumn('milestone_verifications', 'audit_status')) $table->string('audit_status', 20)->nullable();
            if (! Schema::hasColumn('milestone_verifications', 'audit_recommendation')) $table->string('audit_recommendation', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('p_m_audits', fn (Blueprint $table) => $table->dropColumn(['decision', 'decided_at', 'notes']));
        Schema::table('milestone_verifications', fn (Blueprint $table) => $table->dropColumn(['audit_status', 'audit_recommendation']));
    }
};
