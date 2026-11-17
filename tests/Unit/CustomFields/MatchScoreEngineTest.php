<?php

use App\Models\Programs\ProgramCustomField;
use App\Service\Program\MatchScore\MatchScoreEngine;

function pcf(string $key, string $type, array $scoring, array $extra = []): ProgramCustomField
{
    return new ProgramCustomField($extra + ['id' => crc32($key) % 1000, 'key' => $key, 'label' => ucfirst($key), 'type' => $type, 'audience' => 'application', 'is_scoring' => true, 'is_bonus' => false, 'scoring' => $scoring + ['enabled' => true]]);
}

it('scores in_set for select and each multi_select mode', function () {
    $e = new MatchScoreEngine;
    $sel = pcf('city', 'select', ['weight' => 10, 'match_type' => 'in_set', 'target' => ['Niagara', 'London']]);
    expect($e->computeFor([$sel], ['city' => 'London'], null)['score'])->toBe(100)->and($e->computeFor([$sel], ['city' => 'NY'], null)['score'])->toBe(0);
    foreach ([['any', ['NY', 'London'], 100], ['any', ['NY'], 0], ['proportional', ['Niagara'], 50], ['proportional', ['Niagara', 'London', 'NY'], 100], ['all', ['Niagara'], 0], ['all', ['London', 'Niagara', 'NY'], 100]] as [$mode, $answer, $expected]) {
        $f = pcf('places', 'multi_select', ['weight' => 20, 'match_type' => 'in_set', 'mode' => $mode, 'target' => ['Niagara', 'London']]);
        expect($e->computeFor([$f], ['places' => $answer], null)['score'])->toBe($expected, "$mode ".json_encode($answer));
    }
});

it('scores range, equals and provided, with inclusive bounds and dates', function () {
    $e = new MatchScoreEngine;
    $age = pcf('age', 'number', ['weight' => 30, 'match_type' => 'range', 'target' => ['min' => 2, 'max' => 10]]);
    expect(array_map(fn ($v) => $e->computeFor([$age], ['age' => $v], null)['score'], [1, 2, 10, 11, null]))->toBe([0, 100, 100, 0, 0]);
    $open = pcf('rev', 'number', ['weight' => 1, 'match_type' => 'range', 'target' => ['min' => 5000]]);
    expect($e->computeFor([$open], ['rev' => 99999], null)['score'])->toBe(100);
    $date = pcf('founded', 'date', ['weight' => 1, 'match_type' => 'range', 'target' => ['min' => '2020-01-01', 'max' => '2022-12-31']]);
    expect($e->computeFor([$date], ['founded' => '2021-06-01'], null)['score'])->toBe(100)->and($e->computeFor([$date], ['founded' => '2023-01-01'], null)['score'])->toBe(0);
    $eq = pcf('reg', 'boolean', ['weight' => 5, 'match_type' => 'equals', 'target' => true]);
    expect($e->computeFor([$eq], ['reg' => true], null)['score'])->toBe(100)->and($e->computeFor([$eq], ['reg' => false], null)['score'])->toBe(0)->and($e->computeFor([$eq], [], null)['score'])->toBe(0);
    $p = pcf('plan', 'file', ['weight' => 10, 'match_type' => 'provided']);
    expect($e->computeFor([$p], ['plan' => 'path.pdf'], null)['score'])->toBe(100)->and($e->computeFor([$p], ['plan' => ''], null)['score'])->toBe(0);
});

it('normalizes weights that do not add up to 100 and keeps unanswered fields in the denominator', function () {
    $e = new MatchScoreEngine;
    $fields = [pcf('a', 'boolean', ['weight' => 20, 'match_type' => 'equals', 'target' => true]), pcf('b', 'number', ['weight' => 30, 'match_type' => 'range', 'target' => ['min' => 2, 'max' => 10]]), pcf('c', 'file', ['weight' => 10, 'match_type' => 'provided'])];
    $r = $e->computeFor($fields, ['a' => true, 'b' => 5], null);          // (20*1 + 30*1 + 10*0) / 60 = 83.33
    expect($r['score'])->toBe(83)->and($r['breakdown'][2]['match'])->toBe(0.0)->and($r['breakdown'][0]['normalized_weight'])->toBe(33.33);
    expect($e->computeFor($fields, ['a' => true], null)['score'])->toBe(33);   // 20/60
    expect($e->computeFor($fields, ['a' => false, 'b' => 99], null)['score'])->toBe(0);
});

it('rounds half up and caps at 100', function () {
    $e = new MatchScoreEngine;
    $two = [pcf('a', 'boolean', ['weight' => 1, 'match_type' => 'equals', 'target' => true]), pcf('b', 'boolean', ['weight' => 1, 'match_type' => 'equals', 'target' => true])];
    expect($e->computeFor($two, ['a' => true], null)['score'])->toBe(50);
    $eight = array_map(fn ($i) => pcf("k$i", 'boolean', ['weight' => 1, 'match_type' => 'equals', 'target' => true]), range(1, 8));
    expect($e->computeFor($eight, ['k1' => true, 'k2' => true, 'k3' => true, 'k4' => true], null)['score'])->toBe(50);
    $third = array_map(fn ($i) => pcf("t$i", 'boolean', ['weight' => 1, 'match_type' => 'equals', 'target' => true]), range(1, 3));
    expect($e->computeFor($third, ['t1' => true], null)['score'])->toBe(33)->and($e->computeFor($third, ['t1' => true, 't2' => true], null)['score'])->toBe(67);
    $bonus = pcf('bonus', 'boolean', ['weight' => 30, 'match_type' => 'equals', 'target' => true], ['is_bonus' => true]);
    expect($e->computeFor([$two[0], $bonus], ['a' => true, 'bonus' => true], null)['score'])->toBe(100);   // 100 + 30 capped
});

it('gates bonus points behind the minimum match score and returns null without scoring fields', function () {
    $e = new MatchScoreEngine;
    $base = [pcf('a', 'boolean', ['weight' => 1, 'match_type' => 'equals', 'target' => true]), pcf('b', 'boolean', ['weight' => 1, 'match_type' => 'equals', 'target' => true])];
    $bonus = pcf('bonus', 'boolean', ['weight' => 10, 'match_type' => 'equals', 'target' => true], ['is_bonus' => true]);
    $r = $e->computeFor([...$base, $bonus], ['a' => true, 'bonus' => true], 60);     // base 50 < 60: no bonus
    expect($r['score'])->toBe(50)->and($r['eligible_for_bonus'])->toBeFalse()->and($r['bonus'][0]['earned_points'])->toBe(0.0);
    $ok = $e->computeFor([...$base, $bonus], ['a' => true, 'bonus' => true], 50);    // base 50 >= 50: bonus 10
    expect($ok['score'])->toBe(60)->and($ok['eligible_for_bonus'])->toBeTrue();
    expect($e->computeFor([$bonus], ['bonus' => true], null)['score'])->toBeNull()->and($e->computeFor([], [], null)['score'])->toBeNull();
});

it('shows applicants only matched / partial / not matched', function () {
    $e = new MatchScoreEngine;
    $f = pcf('places', 'multi_select', ['weight' => 20, 'match_type' => 'in_set', 'mode' => 'proportional', 'target' => ['Niagara', 'London']]);
    $view = $e->applicantView($e->computeFor([$f], ['places' => ['Niagara']], null));
    expect($view)->toBe([['key' => 'places', 'label' => 'Places', 'status' => 'partial']])->and(json_encode($view))->not->toContain('weight')->not->toContain('target');
});
