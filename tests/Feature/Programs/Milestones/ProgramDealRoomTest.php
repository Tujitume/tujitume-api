<?php

use App\Models\Programs\ProgramMilestone;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Program deal room', function () {
    it('requires authentication to upload a deal room document', function () {
        $milestone = ProgramMilestone::factory()->create(['app_id' => $this->application->id]);
        assertProgramUnauthenticated('POST', "milestones/{$milestone->id}/deal-room");
    });
});
