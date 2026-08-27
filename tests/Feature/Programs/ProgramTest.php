<?php

use App\Http\Resources\ApiResponseResource;
use App\Models\Programs\Program;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Program foundations', function () {
    it('creates a program owned by the organization user', function () {
        expect($this->program->user_id)->toBe($this->orgUser->id)
            ->and($this->program->owner->id)->toBe($this->orgUser->id);
    });

    it('uses the standard success response contract', function () {
        $response = ApiResponseResource::success('Program created.', ['id' => 1], 201);
        expect($response->getStatusCode())->toBe(201)
            ->and($response->getData(true))->toBe(['success' => true, 'message' => 'Program created.', 'data' => ['id' => 1]]);
    });

    it('uses the standard validation response contract', function () {
        $response = ApiResponseResource::error('Validation failed.', ['program_title' => ['Required']], 422);
        expect($response->getData(true)['success'])->toBeFalse()
            ->and($response->getData(true)['errors'])->toHaveKey('program_title');
    });

    // it('requires authentication for program endpoints', function () {
    //     $this->getJson('/api/v1/programs/programs')->assertUnauthorized();
    // });
});
