<?php

use App\Service\AiReview\DocumentFlags;

it('notices a document that says it is fictional, has a placeholder email or leaves template blanks', function () {
    expect(DocumentFlags::of('This is a fictional CV for demonstration purposes. Email: jane@example.com'))->toHaveCount(2)
        ->and(DocumentFlags::of('Name: [your name here]'))->toHaveCount(1)
        ->and(DocumentFlags::of('Led a team of 12 agronomists across three counties.'))->toBe([]);
});
