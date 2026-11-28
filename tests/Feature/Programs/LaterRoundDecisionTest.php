<?php

use App\Models\Programs\Rounds\ProgramRound;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// e2e test 2026-11-27. ISSUE-23: in round 2 or later, Approve answered 422 "Can only accept pending applications"
// (an applicant who advanced is "approved"). ISSUE-29: rejecting them there left status "approved".

beforeEach(function () {
    $this->program->update(['program_type' => 'multi_round', 'total_rounds' => 2]);
    $this->round2 = ProgramRound::factory()->create([
        'program_id' => $this->program->id, 'round_number' => 2, 'status' => 'published', 'advancement_mode' => 'manual',
    ]);
    $this->application->update(['current_round_id' => $this->round2->id, 'status' => 'approved', 'round_status' => 'submitted']);
});

it('approves an applicant in the final round as the round\'s advance does (ISSUE-23)', function () {
    $res = $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/applications/{$this->application->id}/accept");
    $res->assertOk();
    expect($this->application->fresh()->status)->toBe('awarded');
});

it('marks an applicant turned down in a later round as rejected (ISSUE-29)', function () {
    $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/applications/{$this->application->id}/reject")->assertOk();
    expect($this->application->fresh()->status)->toBe('rejected')->and($this->application->fresh()->round_status)->toBe('not_selected');
});
