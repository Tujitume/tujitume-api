<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The business milestone workflow (MilestoneWorkflowHelper) sets pre_release_requested and mid_milestone_submitted, but the
 * status enum did not allow them, so MySQL saved an empty status ("") and the milestone fell out of every list and rule.
 */
return new class extends Migration
{
    private const VALUES = ['locked','to_do','fully_funded','in_progress','done','at_risk','overdue','failed','in_pr_audit','in_mid_audit',
        'in_final_audit','non_compliant','admin_review','reviewed','extended','execution_submitted','continuation_triggered','rmep_submitted',
        'pr_approved','pr_rejected','pre_release_requested','mid_milestone_submitted'];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') return; // SQLite does not enforce the enum

        $list = implode(',', array_map(fn ($v) => "'{$v}'", self::VALUES));
        DB::statement("ALTER TABLE milestones MODIFY status ENUM({$list}) NOT NULL DEFAULT 'to_do'");
        // Milestones already saved with the empty status were asking for pre-release documents
        DB::table('milestones')->where('status', '')->whereExists(fn ($q) => $q->from('milestone_pre_release_requests')->whereColumn('milestone_pre_release_requests.milestone_id', 'milestones.id'))
            ->update(['status' => 'pre_release_requested']);
    }

    public function down(): void
    {
        // Narrowing the enum would empty the statuses that use the new values; leave them.
    }
};
