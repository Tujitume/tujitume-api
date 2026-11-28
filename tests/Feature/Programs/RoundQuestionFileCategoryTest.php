<?php

use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Service\Program\RoundMatchScore;
use Illuminate\Http\UploadedFile;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('File category of a file-upload question', function () {
    $questionsOf = function () {
        $info = $this->actingAsApplicant()->getJson("/api/v1/programs/{$this->program->id}/sme/applications")->assertOk();

        return $info->json('application_round.questions') ?? $info->json('data.application_round.questions');
    };

    it('saves file_category sent on create and update and returns it to applicants', function () use ($questionsOf) {
        $created = $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/questions", [
            'question_text' => 'Photos of the shop', 'question_type' => 'file_upload', 'is_required' => true,
            'options' => ['type:image'], 'file_category' => ['photo'], 'display_order' => 1,
        ])->assertCreated();
        expect($created->json('data.file_category'))->toBe(['photo']);
        $id = $created->json('data.id');

        $this->actingAsOrgOwner()->patchJson("/api/v1/programs/questions/{$id}", ['file_category' => 'photo, video'])
            ->assertOk()->assertJsonPath('data.file_category', ['photo', 'video']);

        expect(collect($questionsOf->call($this))->firstWhere('id', $id)['file_category'])->toBe(['photo', 'video']);
    });

    it('refuses an unknown category', function () {
        $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/questions", [
            'question_text' => 'Sound', 'question_type' => 'file_upload', 'is_required' => true,
            'file_category' => ['audio'], 'display_order' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['file_category']);
    });

    it('reads the category from options for older clients and older questions', function () use ($questionsOf) {
        // an older client still packs the category into options: the column is filled from it
        $created = $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/questions", [
            'question_text' => 'Plan', 'question_type' => 'file_upload', 'is_required' => true,
            'options' => ['document', 'type:pdf'], 'display_order' => 1,
        ])->assertCreated();
        expect($created->json('data.file_category'))->toBe(['document'])
            ->and($created->json('data.options'))->toBe(['document', 'type:pdf']);

        // a question saved before the column existed (written straight to the table) still reports it
        $legacy = RoundCustomQuestion::create([
            'round_id' => $this->round->id, 'question_text' => 'Old', 'question_type' => 'file_upload',
            'options' => ['photo_video'], 'is_required' => false, 'display_order' => 2,
        ]);
        expect($legacy->fresh()->file_category)->toBe(['photo_video'])
            ->and(collect($questionsOf->call($this))->firstWhere('id', $legacy->id)['file_category'])->toBe(['photo_video']);

        // other question types have none
        $text = RoundCustomQuestion::create(['round_id' => $this->round->id, 'question_text' => 'Name', 'question_type' => 'short_answer', 'is_required' => false, 'display_order' => 3]);
        expect($text->fresh()->file_category)->toBeNull();
    });

    it('checks uploads against file_category when the options hold no category', function () {
        $question = RoundCustomQuestion::create([
            'round_id' => $this->round->id, 'question_text' => 'Photo', 'question_type' => 'file_upload',
            'options' => [], 'file_category' => ['photo'], 'is_required' => true, 'display_order' => 1,
        ]);
        $scorer = app(RoundMatchScore::class);
        $scorer->validateFileSet([UploadedFile::fake()->image('shop.jpg')], $question, 'file');
        expect(fn () => $scorer->validateFileSet([UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf')], $question, 'file'))
            ->toThrow(\Illuminate\Validation\ValidationException::class);
    });
});
