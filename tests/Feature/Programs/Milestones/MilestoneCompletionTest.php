<?php

use App\Models\Programs\ProgramMilestone;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Milestone completion', function () {
    it('requires authentication to submit completion evidence', function () {
        $milestone = ProgramMilestone::factory()->create(['app_id' => $this->application->id]);
        assertProgramUnauthenticated('POST', "milestones/{$milestone->id}/completions");
    });
});
