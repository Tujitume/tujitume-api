<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Round finalization', function () {
    it('requires authentication', function () {
        assertProgramUnauthenticated('POST', "rounds/{$this->round->id}/finalize");
    });
    it('rejects a non-owner', function () {
        $this->actingAsApplicant()->postJson("/api/v1/programs/rounds/{$this->round->id}/finalize")->assertStatus(403)->assertJson(['success' => false]);
    });
});
