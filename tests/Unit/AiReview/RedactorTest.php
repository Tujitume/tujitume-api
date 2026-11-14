<?php

use App\Service\AiReview\Redactor;

it('masks emails, phones, ids, bank numbers and social links', function () {
    $r = new Redactor();
    $out = $r->redact('Mail jane.doe@example.com or call +254 712 345 678. ID 12345678, passport A1234567, account 0123456789012. https://www.linkedin.com/in/jane-doe');
    expect($out)->not->toContain('jane.doe@example.com')
        ->and($out)->not->toContain('712 345 678')
        ->and($out)->not->toContain('12345678')
        ->and($out)->not->toContain('A1234567')
        ->and($out)->not->toContain('linkedin.com')
        ->and($out)->toContain('[email address: valid format, company domain (example.com)]')->and($out)->toContain('[social-profile]');
    expect($r->lastCount)->toBeGreaterThan(3);
});

it('replaces the applicant name but keeps normal text', function () {
    $out = (new Redactor())->redact('Jane Wanjiru founded the company. We process maize.', 'Jane Wanjiru');
    expect($out)->toContain('the applicant')->and($out)->not->toContain('Wanjiru')->and($out)->toContain('maize');
});

it('does not mask plain numbers like revenue percentages', function () {
    expect((new Redactor())->redact('Revenue grew 40% to 2500 customers in 2025.'))->toContain('40%')->toContain('2500');
});

it('says what a hidden email or phone number was, so it is not mistaken for a placeholder', function () {
    $r = new Redactor();
    expect($r->redact('bazu@tujitume.com'))->toBe('[email address: valid format, company domain (tujitume.com)]')
        ->and($r->redact('Bazu@gmail.com'))->toContain('free webmail provider (gmail.com)')
        ->and($r->redact('+254 712 345 678'))->toBe('[number: 12 digits, starts with country code +254, valid Kenyan mobile format]')
        ->and($r->redact('0712345678'))->toBe('[number: 10 digits, local format with a leading 0]')
        ->and($r->redact('25439939393939939'))->toContain('not a valid Kenyan mobile format')
        ->and($r->redact('93721601031'))->toBe('[number: 11 digits, starts with country code +93]');
});

it('describes the answer to a name question instead of swapping its parts for "the applicant"', function () {
    $r = new Redactor();
    expect($r->describeName('Richard Branson Smith'))->toBe('[personal name given: 3 words, plausible first and last name format]')
        ->and($r->describeName('Owen'))->toContain('1 word only')
        ->and($r->describeName('Owen 2'))->toContain('not in a plausible name format')
        ->and($r->describeName('Richard Branson Smith'))->not->toContain('the applicant');
});
