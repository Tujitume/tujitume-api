<?php

use App\Models\Programs\ProgramMilestone;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Milestone pre-agreements', function () {
    it('requires authentication to comment', function () {
        $milestone = ProgramMilestone::factory()->create(['app_id' => $this->application->id]);
        assertProgramUnauthenticated('POST', "milestones/{$milestone->id}/agreements/mprv/comment");
    });
});
