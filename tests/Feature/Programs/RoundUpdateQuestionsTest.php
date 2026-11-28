<?php

use App\Models\Programs\Rounds\RoundCustomQuestion;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// e2e test 2026-11-27 (fixer, from laravel.log): updating a round with its questions tried to write a `round_questions`
// column (store was fixed by the builder; update had the same bug). The reviewed_by agreement fix is tested in
// Milestones/MilestonePreAgreementTest.php.

it('updates a round together with its questions', function () {
    $this->actingAsOrgOwner()->patchJson("/api/v1/programs/rounds/{$this->round->id}", [
        'round_name' => 'Round 1: Application',
        'round_questions' => [
            ['question_text' => 'Upload your business plan.', 'question_type' => 'file_upload', 'is_required' => true, 'display_order' => 1],
        ],
    ])->assertSuccessful();

    expect($this->round->fresh()->round_name)->toBe('Round 1: Application')
        ->and(RoundCustomQuestion::where('round_id', $this->round->id)->pluck('question_text')->all())->toContain('Upload your business plan.');
});
