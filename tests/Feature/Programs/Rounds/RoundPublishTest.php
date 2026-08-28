<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Publish Round', function () {
    it('requires authentication', function () {
        assertProgramUnauthenticated('POST', "rounds/{$this->round->id}/publish");
    });
    it('rejects an applicant', function () {
        $this->actingAsApplicant()->postJson("/api/v1/programs/rounds/{$this->round->id}/publish")->assertStatus(403)->assertJson(['success' => false]);
    });
});
