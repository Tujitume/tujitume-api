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
        ->and($out)->toContain('[email]')->and($out)->toContain('[social-profile]');
    expect($r->lastCount)->toBeGreaterThan(3);
});

it('replaces the applicant name but keeps normal text', function () {
    $out = (new Redactor())->redact('Jane Wanjiru founded the company. We process maize.', 'Jane Wanjiru');
    expect($out)->toContain('the applicant')->and($out)->not->toContain('Wanjiru')->and($out)->toContain('maize');
});

it('does not mask plain numbers like revenue percentages', function () {
    expect((new Redactor())->redact('Revenue grew 40% to 2500 customers in 2025.'))->toContain('40%')->toContain('2500');
});
