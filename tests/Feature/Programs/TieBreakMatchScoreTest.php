<?php

use App\Http\Controllers\Program\Rounds\ProgramRoundController;
use App\Models\Programs\ProgramApplication;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// ISSUE-24 (e2e test 2026-11-27): with "rank by match score", two applicants tied on score AND match score were split
// arbitrarily (by id). That is still a tie for the owner to decide.

function tieApps($test, array $matchScores)
{
    return collect($matchScores)->map(fn ($m, $i) => ProgramApplication::factory()->create([
        'program_id' => $test->program->id, 'current_round_id' => $test->round->id, 'program_owner_id' => $test->orgUser->id,
        'round_status' => 'scored', 'average_score' => 80, 'match_score' => $m, 'startup_name' => 'Tied '.$i,
    ]));
}

function selectFor($test, $apps)
{
    $test->round->update(['advancement_mode' => 'fixed_quota', 'max_advancing' => 1, 'tie_breaker_rule' => 'secondary_metric']);
    return (fn ($round, $apps) => $this->selectByRule($round, $apps))->call(app(ProgramRoundController::class), $test->round->fresh(), $apps);
}

it('keeps a tie open when the match score is the same too', function () {
    $selection = selectFor($this, tieApps($this, [90, 90]));
    expect($selection['tie'])->not->toBeNull()->and($selection['tie']['resolved'])->toBeNull();
});

it('settles the tie by match score when it differs', function () {
    $selection = selectFor($this, tieApps($this, [85, 90]));
    expect($selection['tie']['resolved'])->toContain('match score')
        ->and(collect($selection['advanced'])->pluck('match_score')->map(fn ($v) => (float) $v)->all())->toBe([90.0]);
});
