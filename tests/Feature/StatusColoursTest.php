<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

// DECISION-7 (owner 27 Nov): every status the API sends has a colour by one rule. This fails when a status column gains
// a value with no colour in config/status.php, or the config keeps a value the column cannot hold.

const STATUS_COLUMNS = [
    'program' => ['programs', 'status'],
    'program_round' => ['program_rounds', 'status'],
    'program_application' => ['program_applications', 'status'],
    'program_application_round' => ['program_applications', 'round_status'],
    'program_milestone' => ['program_milestones', 'status'],
    'milestone_fund_release' => ['program_milestones', 'fund_release_status'],
    'milestone_pre_agreement' => ['milestone_pre_agreements', 'status'],
    'milestone_verification' => ['milestone_verifications', 'decision'],
    'disbursement' => ['disbursements', 'status'],
    'milestone_completion' => ['milestone_completion_submissions', 'decision'],
    'funding_setup' => ['program_applications', 'funding_setup_status'],
    'program_wallet' => ['program_wallets', 'status'],
    'knockout' => ['program_applications', 'knockout_status'],
    'me_checkpoint' => ['m_e_checkpoints', 'status'],
    'me_site_visit' => ['m_e_site_visits', 'status'],
    'reviewer_order' => ['reviewer_orders', 'work_status'],
    'reviewer_payment' => ['reviewer_orders', 'payment_status'],
];

/** The values an enum column allows (SQLite keeps them as a CHECK constraint, MySQL as the enum type). */
function enumValues(string $table, string $column): array
{
    if (DB::getDriverName() === 'sqlite') {
        $sql = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->sql;
        preg_match('/"'.preg_quote($column, '/').'"\s+varchar[^,]*?check\s*\(\s*"'.preg_quote($column, '/').'"\s+in\s*\(([^)]*)\)/i', $sql, $m);
    } else {
        $type = DB::selectOne("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column])->Type;
        preg_match('/enum\((.*)\)/i', $type, $m);
    }
    preg_match_all("/'([^']*)'/", $m[1] ?? '', $values);

    return $values[1];
}

it('has a colour for every value of every status column, and no colours for values that cannot occur', function () {
    foreach (STATUS_COLUMNS as $group => [$table, $column]) {
        $values = enumValues($table, $column);
        $colours = config("status.{$group}", []);
        expect($values)->not->toBeEmpty("{$table}.{$column} should be an enum");
        expect(array_values(array_diff($values, array_keys($colours))))->toBe([], "status.{$group} is missing a colour");
        expect(array_values(array_diff(array_keys($colours), $values)))->toBe([], "status.{$group} has a value {$table}.{$column} cannot hold");
    }
});

it('only uses the five colours of the rule', function () {
    foreach (config('status') as $group => $colours) {
        expect(array_diff(array_values($colours), ['success', 'warning', 'danger', 'info', 'neutral']))->toBe([], "status.{$group}");
    }
});

it('sends value, label and colour for a status', function () {
    expect(\App\Support\ApiContract::statusMeta('program_application_round', 'assigned'))
        ->toBe(['value' => 'assigned', 'label' => 'Assigned', 'color' => 'warning']);
});
