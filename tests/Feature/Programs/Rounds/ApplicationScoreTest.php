<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Application scoring', function () {
    it('requires authentication to submit a score', function () {
        assertProgramUnauthenticated('POST', "applications/{$this->application->id}/scores");
    });
    it('rejects an unassigned applicant scoring an application', function () {
        $this->actingAsApplicant()->postJson("/api/v1/programs/applications/{$this->application->id}/scores", [])->assertStatus(403)->assertJson(['success' => false]);
    });
});
