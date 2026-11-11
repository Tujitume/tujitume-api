<?php

use App\Service\AiReview\QuoteVerifier;
use App\Service\AiReview\Redactor;

function sources(): array
{
    return [['type' => 'document', 'id' => 5, 'label' => 'Plan', 'text' => "Our rn1ssion is to grow.\nWe sell to 200 farmers.", 'pages' => [1 => 'Intro page text here', 2 => 'We sell to 200 farmers in Kenya.']],
        ['type' => 'answer', 'id' => 9, 'label' => 'Q', 'text' => 'We run a women-led company.', 'pages' => null]];
}

it('verifies exact, whitespace and case-insensitive quotes', function () {
    $v = new QuoteVerifier(new Redactor());
    expect($v->verify('women-led   company', ['type' => 'answer', 'id' => 9], sources()))->toBeTrue()
        ->and($v->verify('WE SELL TO 200 FARMERS', ['type' => 'document', 'id' => 5, 'page' => 2], sources()))->toBeTrue();
});

it('tolerates common OCR artifacts', function () {
    $v = new QuoteVerifier(new Redactor());
    expect($v->verify('Our mission is to grow', ['type' => 'document', 'id' => 5], sources()))->toBeTrue();
});

it('rejects invented quotes, wrong sources and empty quotes', function () {
    $v = new QuoteVerifier(new Redactor());
    expect($v->verify('We have 10,000 paying customers', ['type' => 'document', 'id' => 5], sources()))->toBeFalse()
        ->and($v->verify('women-led company', ['type' => 'document', 'id' => 5], sources()))->toBeFalse()
        ->and($v->verify('women-led company', ['type' => 'answer', 'id' => 999], sources()))->toBeFalse()
        ->and($v->verify('', ['type' => 'answer', 'id' => 9], sources()))->toBeFalse();
});
