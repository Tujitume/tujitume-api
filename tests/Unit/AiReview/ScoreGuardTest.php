<?php

use App\Models\Programs\Rounds\ProgramRound;
use App\Service\AiReview\ScoreGuard;

function guardRound(): ProgramRound
{
    return new ProgramRound(['scoring_criteria' => [
        ['name' => 'Company email validity', 'score_range' => '0-5'],
        ['name' => 'Mobile phone number completeness', 'score_range' => '0-5'],
        ['name' => 'Full name provided', 'score_range' => '0-5'],
        ['name' => 'Business model', 'score_range' => '0-10'],
    ]]);
}

function guardInput(string $email, string $phone, string $name): array
{
    return ['sources' => [
        ['type' => 'answer', 'id' => 1, 'label' => 'Your company email', 'text' => $email],
        ['type' => 'answer', 'id' => 2, 'label' => 'Your mobile phone number', 'text' => $phone],
        ['type' => 'answer', 'id' => 3, 'label' => 'Your first and last name', 'text' => $name],
    ]];
}

function guardScores(): array
{
    return [
        ['criterion' => 'Company email validity', 'score' => 5.0, 'max' => 5.0, 'rationale' => 'ok'],
        ['criterion' => 'Mobile phone number completeness', 'score' => 5.0, 'max' => 5.0, 'rationale' => 'ok'],
        ['criterion' => 'Full name provided', 'score' => 5.0, 'max' => 5.0, 'rationale' => 'ok'],
        ['criterion' => 'Business model', 'score' => 7.0, 'max' => 10.0, 'rationale' => 'fine'],
    ];
}

it('leaves good answers alone and never touches other criteria', function () {
    $out = collect(app(ScoreGuard::class)->apply(guardScores(), guardRound(), guardInput('bazu@tujitume.com', '+254712345678', 'Owen Smith')))->pluck('score', 'criterion');

    expect($out['Company email validity'])->toBe(5.0)->and($out['Mobile phone number completeness'])->toBe(5.0)
        ->and($out['Full name provided'])->toBe(5.0)->and($out['Business model'])->toBe(7.0);
});

it('scores a malformed email, phone or name 0, whatever the model said', function () {
    $out = collect(app(ScoreGuard::class)->apply(guardScores(), guardRound(), guardInput('not an email', '25439939393939939', 'Owen')))->keyBy('criterion');

    expect($out['Company email validity']['score'])->toBe(0.0)->and($out['Mobile phone number completeness']['score'])->toBe(0.0)
        ->and($out['Full name provided']['score'])->toBe(0.0)->and($out['Mobile phone number completeness']['rationale'])->toContain('earns no points')
        ->and($out['Business model']['score'])->toBe(7.0);
});

it('gives a free webmail address only part credit for a company email', function () {
    $out = collect(app(ScoreGuard::class)->apply(guardScores(), guardRound(), guardInput('Bazu@gmail.com', '0712 345 678', 'Owen Smith')))->keyBy('criterion');

    expect($out['Company email validity']['score'])->toBe(1.0)->and($out['Company email validity']['rationale'])->toContain('free webmail');
});

it('checks the question the criterion is about when a form asks for two emails', function () {
    $round = new ProgramRound(['scoring_criteria' => [
        ['name' => 'Company email validity', 'description' => 'Confirm that a corporate email address is supplied. Based on: Q3 “Your company email”.', 'score_range' => '0-5'],
    ]]);
    $input = ['sources' => [
        ['type' => 'answer', 'id' => 1, 'label' => 'Email', 'text' => 'ofulakelvin@gmail.com'],
        ['type' => 'answer', 'id' => 2, 'label' => 'Your first and last name', 'text' => 'Richard Branson smith'],
        ['type' => 'answer', 'id' => 3, 'label' => 'Your company email', 'text' => 'kelvin@lipr.io'],
    ]];
    $scores = [['criterion' => 'Company email validity', 'score' => 5.0, 'max' => 5.0, 'rationale' => 'A company-domain address was given.']];

    $out = app(ScoreGuard::class)->apply($scores, $round, $input);

    // The company email is a company domain, so the full score stands (it is not judged by the gmail address in the other question)
    expect($out[0]['score'])->toBe(5.0)->and($out[0]['rationale'])->toBe('A company-domain address was given.');
});

it('gives full credit to a plainly valid name and phone for a "provided / complete" criterion', function () {
    $out = collect(app(ScoreGuard::class)->apply(
        [['criterion' => 'Full name provided', 'score' => 3.0, 'max' => 5.0, 'rationale' => 'extra wording'], ['criterion' => 'Mobile phone number completeness', 'score' => 4.0, 'max' => 5.0, 'rationale' => 'ok']],
        guardRound(),
        guardInput('bazu@tujitume.com', '+254712345678', 'Richard Branson smith')
    ))->keyBy('criterion');

    expect($out['Full name provided']['score'])->toBe(5.0)->and($out['Mobile phone number completeness']['score'])->toBe(5.0);
});

it('gives full credit to a valid name for a criterion worded "Provision of personal name"', function () {
    $round = new ProgramRound(['scoring_criteria' => [['name' => 'Provision of personal name', 'score_range' => '0-5']]]);
    $input = ['sources' => [['type' => 'answer', 'id' => 1, 'label' => 'Your first and last name', 'text' => 'Richard Branson Smith']]];
    $out = app(ScoreGuard::class)->apply([['criterion' => 'Provision of personal name', 'score' => 3.0, 'max' => 5.0, 'rationale' => 'unclear']], $round, $input);

    expect($out[0]['score'])->toBe(5.0);
});
