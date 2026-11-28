<?php

use App\Models\Programs\Program;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// A program that is still a draft, or hidden, or no longer open, never reaches applicants: not in the
// lists, not by id, not through the public link. Its own team still sees it.
describe('Draft and hidden programs stay out of applicants\' sight', function () {
    beforeEach(function () {
        $this->draft = Program::factory()->create(['user_id' => $this->orgUser->id, 'status' => 'draft', 'visible' => 0, 'public_id' => 'draft-public-id']);
        $this->hidden = Program::factory()->create(['user_id' => $this->orgUser->id, 'status' => 'published', 'visible' => 0]);
        $this->closed = Program::factory()->create(['user_id' => $this->orgUser->id, 'status' => 'closed', 'visible' => 1]);
    });

    it('answers a draft, hidden or closed program by id with 404 to an applicant', function () {
        foreach ([$this->draft, $this->hidden, $this->closed] as $program) {
            $this->actingAsApplicant()->getJson("/api/v1/programs/get_program/{$program->id}")
                ->assertNotFound()->assertJson(['success' => false]);
        }
    });

    it('still shows the owner their own draft', function () {
        $this->actingAsOrgOwner()->getJson("/api/v1/programs/get_program/{$this->draft->id}")
            ->assertOk()->assertJsonPath('data.program.id', $this->draft->id);
    });

    it('leaves drafts, hidden and closed programs out of the discover list', function () {
        $ids = collect($this->actingAsApplicant()->getJson('/api/v1/programs/public-programs')->assertOk()->json('data.items'))->pluck('id');

        expect($ids)->toContain($this->program->id)
            ->not->toContain($this->draft->id)
            ->not->toContain($this->hidden->id)
            ->not->toContain($this->closed->id);
    });

    it('hides a draft from the apply form info', function () {
        $this->actingAsApplicant()->getJson("/api/v1/programs/{$this->draft->id}/sme/applications")->assertNotFound();
    });
});
