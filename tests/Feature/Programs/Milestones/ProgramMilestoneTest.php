<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Milestone templates', function () {
    it('requires authentication to create a template', function () {
        assertProgramUnauthenticated('POST', "applications/{$this->application->id}/milestones/templates");
    });
    it('rejects an applicant attempting owner template creation', function () {
        $this->actingAsApplicant()->postJson("/api/v1/programs/applications/{$this->application->id}/milestones/templates", [])->assertStatus(403)->assertJson(['success' => false]);
    });
});
