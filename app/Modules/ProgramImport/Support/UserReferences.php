<?php

namespace App\Modules\ProgramImport\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Has a user gained anything that rolling back the import would orphan or destroy? */
class UserReferences
{
    private const CHECKS = [
        ['program_applications', 'user_id'], ['notifications', 'receiver_id'], ['notifications', 'customer_id'], ['kyc_verifications', 'user_id'],
        ['round_reviewers', 'user_id'], ['reviewer_orders', 'reviewer_id'], ['reviewer_application_assignments', 'reviewer_id'], ['lipr_payments', 'user_id'],
        ['personal_access_tokens', 'tokenable_id'], ['user_settings', 'user_id'], ['milestone_verifications', 'auditor_id'], ['m_e_submissions', 'submitted_by'],
        ['application_scores', 'reviewer_id'],
    ];

    /**
     * @param  list<int>  $ignoreApplicationIds  applications created by the batch (they go away with the rollback)
     * @return list<string> human descriptions of what refers to the user
     */
    public static function foreign(int $userId, array $ignoreApplicationIds = []): array
    {
        $found = [];
        foreach (self::CHECKS as [$table, $col]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) {
                continue;
            }
            $q = DB::table($table)->where($col, $userId);
            if ($table === 'program_applications' && $ignoreApplicationIds) {
                $q->whereNotIn('id', $ignoreApplicationIds);
            }
            if ($table === 'user_settings' || $table === 'application_scores') {
                continue; // created by the platform for any user / by the import itself
            }
            $q->exists() && $found[] = "$table.$col";
        }

        return $found;
    }
}
