<?php

use App\Service\AiReview\ResponseValidator;

it('accepts a valid report and normalises it', function () {
    $r = (new ResponseValidator())->validate([
        'summary' => 'ok', 'findings' => [['dimension' => 'traction', 'polarity' => 'gap', 'title' => 'T', 'body' => 'B', 'source_type' => 'answer', 'source_id' => 'A12', 'quote' => 'some quote here']],
        'scores' => [['criterion' => 'Impact', 'score' => '7', 'rationale' => 'r']],
    ], ['Impact'], true);
    expect($r['errors'])->toBe([])->and($r['data']['findings'][0]['source_id'])->toBe(12)->and($r['data']['scores'][0]['score'])->toBe(7.0);
});

it('rejects malformed output', function () {
    $v = new ResponseValidator();
    expect($v->validate(null, [], false)['errors'])->not->toBe([]);
    expect($v->validate(['summary' => '', 'findings' => 'x'], [], false)['errors'])->not->toBe([]);
    $bad = $v->validate(['summary' => 's', 'findings' => [['dimension' => 'evil', 'polarity' => 'gap', 'title' => 'T', 'body' => 'B']]], [], false);
    expect($bad['errors'])->toContain('findings[0].dimension is not allowed');
    $noQuote = $v->validate(['summary' => 's', 'findings' => [['dimension' => 'traction', 'polarity' => 'gap', 'title' => 'T', 'body' => 'B', 'source_type' => 'document', 'source_id' => 3]]], [], false);
    expect(implode(' ', $noQuote['errors']))->toContain('quote is required');
    expect($v->validate(['summary' => 's', 'findings' => []], ['Impact'], true)['errors'])->toContain('scores must be an array');
});
