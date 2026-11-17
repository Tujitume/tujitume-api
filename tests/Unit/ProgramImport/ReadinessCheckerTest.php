<?php

use App\Modules\ProgramImport\Readiness\ReadinessChecker;

function pi_program(array $o = []): array
{
    return $o + ['program_title' => 'P', 'total_program_amount' => 100000.0, 'funding_per_business' => 25000.0, 'currency' => 'KES',
        'disbursement_type' => 'supplier', 'program_type' => 'multi_round', 'total_rounds' => 2,
        'application_start_date' => '2026-01-01', 'application_deadline' => '2026-02-01'];
}

it('blocks a review import without applications and shows a landing preview otherwise', function () {
    $c = new ReadinessChecker;
    $bad = $c->check('review', ['program' => [pi_program()], 'rounds' => [['round_number' => 1]]]);
    expect($bad['ready'])->toBeFalse()->and(array_column($bad['blockers'], 'code'))->toContain('missing_sheet');

    $ok = $c->check('review', [
        'program' => [pi_program()],
        'rounds' => [['round_number' => 1, 'open_date' => '2026-01-01', 'close_date' => '2026-02-01'], ['round_number' => 2, 'open_date' => '2026-03-01', 'close_date' => '2026-04-01']],
        'applications' => [['application_ref' => 'a1', 'round_number' => 2, 'email' => 'x@y.com'], ['application_ref' => 'a2', 'round_number' => 2, 'email' => 'z@y.com']],
    ]);
    expect($ok['ready'])->toBeTrue()
        ->and($ok['landing_preview']['program']['status'])->toBe('under_review')
        ->and($ok['landing_preview']['rounds'])->toBe([1 => 'finalized', 2 => 'published'])
        ->and($ok['landing_preview']['applications'])->toBe(['approved / submitted' => 2])
        ->and($ok['next_steps'])->not->toBeEmpty();
});

it('requires a score for every application at finalization', function () {
    $r = (new ReadinessChecker)->check('finalization', [
        'program' => [pi_program(['program_type' => 'single_round', 'total_rounds' => 1])], 'rounds' => [['round_number' => 1]],
        'applications' => [['application_ref' => 'a1', 'round_number' => 1, 'email' => 'x@y.com'], ['application_ref' => 'a2', 'round_number' => 1, 'email' => 'q@y.com']],
        'scores' => [['application_ref' => 'a1', 'total_score' => 80]],
    ]);
    expect(array_column($r['blockers'], 'code'))->toContain('missing_scores');
});

it('enforces max awardees unless raised, and milestone totals', function () {
    $sheets = fn () => ['program' => [pi_program(['max_awardees' => 1])],
        'winners' => [['application_ref' => 'w1', 'awarded_amount' => 20000.0, 'email' => 'a@b.com'], ['application_ref' => 'w2', 'awarded_amount' => 20000.0, 'email' => 'b@b.com']],
        'milestones' => [['application_ref' => 'w1', 'sequence_order' => 1, 'amount' => 25000.0]]];
    $c = new ReadinessChecker;
    $r = $c->check('disbursement', $sheets());
    expect(array_column($r['blockers'], 'code'))->toContain('over_max_awardees', 'milestones_exceed_award');
    $r2 = $c->check('disbursement', $sheets(), ['raise_max_awardees' => true]);
    expect(array_column($r2['blockers'], 'code'))->not->toContain('over_max_awardees');
});

it('allows partially released milestones at disbursement with a remainder warning (escrow fix)', function () {
    $r = (new ReadinessChecker)->check('disbursement', [
        'program' => [pi_program()],
        'winners' => [['application_ref' => 'w1', 'awarded_amount' => 20000.0, 'email' => 'a@b.com']],
        'milestones' => [
            ['application_ref' => 'w1', 'sequence_order' => 1, 'amount' => 10000.0, 'fund_release_status' => 'released'],
            ['application_ref' => 'w1', 'sequence_order' => 2, 'amount' => 10000.0],
        ],
    ]);
    expect(array_column($r['blockers'], 'code'))->not->toContain('partial_release_blocked')->and(array_column($r['warnings'], 'code'))->toContain('partial_release_remainder');
});

it('catches inverted dates and unsupported currency', function () {
    $r = (new ReadinessChecker)->check('setup', ['program' => [pi_program(['application_deadline' => '2025-12-01', 'currency' => 'EUR'])], 'rounds' => [['round_number' => 1]]]);
    expect(array_column($r['blockers'], 'code'))->toContain('dates_inverted', 'currency_unsupported');
});
