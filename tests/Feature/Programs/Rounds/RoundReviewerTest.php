<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Round reviewers', function () {
    it('requires authentication to assign reviewers', function () {
        assertProgramUnauthenticated('POST', "rounds/{$this->round->id}/reviewers");
    });
    it('rejects an applicant assigning a reviewer', function () {
        $this->actingAsApplicant()->postJson("/api/v1/programs/rounds/{$this->round->id}/reviewers", ['user_id' => $this->reviewerUser->id])->assertStatus(403)->assertJson(['success' => false]);
    });
});
