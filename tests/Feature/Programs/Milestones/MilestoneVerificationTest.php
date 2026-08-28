<?php

use App\Models\Programs\ProgramMilestone;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Milestone verification', function () {
    it('requires authentication to submit verification', function () {
        $milestone = ProgramMilestone::factory()->create(['app_id' => $this->application->id]);
        assertProgramUnauthenticated('POST', "milestones/{$milestone->id}/verifications");
    });
});
