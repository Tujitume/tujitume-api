<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who approved or rejected a milestone's step agreement (MilestonePreAgreementController writes it on review, and
 * clears it when the applicant responds). The model and controller have used it since the agreements were built, but
 * the column was never created, so every approval failed with "Unknown column 'reviewed_by'".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('milestone_pre_agreements', 'reviewed_by')) return;

        Schema::table('milestone_pre_agreements', function (Blueprint $table) {
            $table->foreignId('reviewed_by')->nullable()->after('submitted_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('milestone_pre_agreements', 'reviewed_by')) return;

        Schema::table('milestone_pre_agreements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
        });
    }
};
