<?php

namespace App\Modules\ProgramImport\Rollback;

use App\Models\Programs\ProgramWallet;
use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportRecord;
use App\Modules\ProgramImport\Models\ImportUser;
use App\Modules\ProgramImport\Pipeline\CommitRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A batch may be undone only while nothing downstream has happened to what it created. */
class RollbackGuard
{
    /** @return list<array{code: string, message: string}> */
    public function blockers(ImportBatch $batch): array
    {
        $b = [];
        $add = function (string $code, string $msg) use (&$b) { $b[] = ['code' => $code, 'message' => $msg]; };
        if (! in_array($batch->status, ['completed', 'completed_with_errors'], true)) {
            return [['code' => 'not_committed', 'message' => 'Only a committed import can be rolled back.']];
        }
        $created = fn (string $class) => ImportRecord::where('batch_id', $batch->id)->where('record_type', $class)->where('action', 'created');

        foreach (CommitRunner::LANDED as $class => $attrs) {
            foreach ($created($class)->get() as $r) {
                $m = $class::find($r->record_id);
                $after = $r->before['_after'] ?? [];
                if (! $m) {
                    $add('record_missing', class_basename($class).' #'.$r->record_id.' no longer exists.');

                    continue;
                }
                foreach ($after as $k => $v) {
                    if ((string) $m->getAttribute($k) !== (string) $v) {
                        $add('changed_after_import', class_basename($class)." #{$m->getKey()} changed after import ($k).");
                        break;
                    }
                }
            }
        }

        $apps = $created(\App\Models\Programs\ProgramApplication::class)->pluck('record_id');
        $rounds = $created(\App\Models\Programs\Rounds\ProgramRound::class)->pluck('record_id');
        $milestones = $created(\App\Models\Programs\ProgramMilestone::class)->pluck('record_id');
        $programs = $created(\App\Models\Programs\Program::class)->pluck('record_id');
        $ids = fn (string $class) => $created($class)->pluck('record_id')->all();
        $count = fn ($q) => $q->count() > 0;

        if ($apps->isNotEmpty()) {
            $scores = DB::table('application_scores')->whereIn('application_id', $apps)->whereNotIn('id', $ids(\App\Models\Programs\Rounds\ApplicationScore::class));
            $count($scores) && $add('scores_by_others', 'Scores were added to these applications after the import.');
            $this->when('reviewer_application_assignments', 'application_id', $apps, $add, 'reviewers_assigned', 'Business Coaches were assigned to imported applications.');
            $this->when('m_e_checkpoints', 'app_id', $apps, $add, 'checkpoints_added', 'Checkpoints were added to imported applications.', $ids(\App\Models\Programs\Monitoring\MECheckpoint::class));
            $this->when('m_e_site_visits', 'app_id', $apps, $add, 'site_visits_added', 'Site visits were planned for imported applications.', $ids(\App\Models\Programs\Monitoring\MESiteVisit::class));
            $this->when('reviewer_orders', 'program_id', $programs, $add, 'reviewer_orders', 'Business Coach orders exist for an imported program.');
            if ($count(DB::table('program_applications')->whereIn('id', $apps)->where('escrow_funded', true))) {
                $add('escrow_funded', 'Escrow was funded for an imported application.');
            }
            $answers = DB::table('application_round_responses')->whereIn('application_id', $apps)->whereNotIn('id', $ids(\App\Models\Programs\Rounds\ApplicationRoundResponse::class));
            $count($answers) && $add('answers_changed', 'Applicants added answers after the import.');
        }
        if ($rounds->isNotEmpty()) {
            $this->when('round_reviewers', 'round_id', $rounds, $add, 'reviewers_on_round', 'Business Coaches were added to an imported round.', $ids(\App\Models\Programs\Rounds\RoundReviewer::class));
        }
        if ($milestones->isNotEmpty()) {
            foreach (['milestone_verifications' => 'Verifications', 'milestone_completion_submissions' => 'Completion submissions', 'milestone_pre_agreements' => 'Pre-agreements'] as $t => $label) {
                $this->when($t, 'milestone_id', $milestones, $add, 'milestone_activity', "$label were added to imported milestones.");
            }
            $this->when('disbursements', 'milestone_id', $milestones, $add, 'payments_added', 'Payments were made on imported milestones after the import.', $ids(\App\Models\Programs\Disbursement::class));
        }
        if ($programs->isNotEmpty()) {
            $new = DB::table('program_applications')->whereIn('program_id', $programs)->whereNotIn('id', $apps->all());
            $count($new) && $add('new_applications', 'New applications arrived in an imported program.');
            foreach (ProgramWallet::whereIn('program_id', $programs)->get() as $w) {
                if ((float) $w->balance != 0 || (float) $w->total_deposited != 0 || $w->status !== 'inactive') {
                    $add('wallet_used', 'An imported program\'s wallet has been funded or activated.');
                }
            }
        }
        foreach ($created(\App\Models\Auth\User::class)->pluck('record_id') as $uid) {
            if ($refs = \App\Modules\ProgramImport\Support\UserReferences::foreign((int) $uid, $apps->all())) {
                $add('user_referenced', 'An imported applicant account has since gained other data ('.implode(', ', $refs).').');
                break;
            }
        }
        if (ImportUser::where('batch_id', $batch->id)->whereNotNull('claimed_at')->exists()
            || DB::table('users')->whereIn('id', ImportUser::where('batch_id', $batch->id)->pluck('user_id'))->whereNotNull('password')->exists()) {
            $add('users_claimed', 'An imported applicant has claimed their account.');
        }

        return $b;
    }

    private function when(string $table, string $col, $in, callable $add, string $code, string $msg, array $except = []): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        if (DB::table($table)->whereIn($col, $in)->when($except, fn ($q) => $q->whereNotIn('id', $except))->exists()) {
            $add($code, $msg);
        }
    }
}
