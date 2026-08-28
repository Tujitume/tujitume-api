<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Round documents', function () {
    it('requires authentication to upload documents', function () {
        assertProgramUnauthenticated('POST', "applications/{$this->application->id}/rounds/{$this->round->id}/documents");
    });
    it('rejects an incomplete document upload', function () {
        $this->actingAsApplicant()->postJson("/api/v1/programs/applications/{$this->application->id}/rounds/{$this->round->id}/documents", [])->assertStatus(422)->assertJson(['success' => false])->assertJsonStructure(['errors']);
    });
});
