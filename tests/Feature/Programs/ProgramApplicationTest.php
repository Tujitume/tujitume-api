<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Application endpoints', function () {
    function applicationPayload($businessId): array
    {
        return ['business_id' => $businessId, 'startup_name' => 'GreenField Agri', 'contact_person_name' => 'Amina Wanjiku', 'contact_person_email' => 'amina@example.test', 'sector' => 'agriculture', 'headquarters_location' => 'Nairobi', 'total_amount_requested' => 10000, 'match_score' => 78.5, 'score_breakdown' => ['sector_alignment' => 30]];
    }

    it('requires authentication to submit an application', function () {
        assertProgramUnauthenticated('POST', "{$this->program->id}/applications");
    });

    it('rejects an incomplete application request with the standard error envelope', function () {
        $this->actingAsApplicant()->postJson("/api/v1/programs/{$this->program->id}/applications", [])
            ->assertStatus(422)->assertJson(['success' => false])->assertJsonStructure(['message', 'errors']);
    });

    it('submits an application for a published program and returns 201 created', function () {
        $applicant = \App\Models\Auth\User::factory()->create(['user_type_id' => 1]);
        $this->createVerifiedKyc($applicant);
        $business = \App\Models\Business\Listing::create(['user_id' => $applicant->id, 'name' => 'GreenField Agri']);

        $this->actingAs($applicant, 'sanctum')->postJson("/api/v1/programs/{$this->program->id}/applications", applicationPayload($business->id))
            ->assertCreated()
            ->assertJson(['success' => true, 'message' => 'Application submitted successfully.'])
            ->assertJsonPath('data.program_id', $this->program->id)
            ->assertJsonPath('data.user_id', $applicant->id)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('program_applications', ['program_id' => $this->program->id, 'user_id' => $applicant->id, 'status' => 'pending']);
    });

    it('rejects a duplicate application with conflict status', function () {
        $applicant = \App\Models\Auth\User::factory()->create(['user_type_id' => 1]);
        $this->createVerifiedKyc($applicant);
        $business = \App\Models\Business\Listing::create(['user_id' => $applicant->id, 'name' => 'GreenField Agri']);
        $this->actingAs($applicant, 'sanctum')->postJson("/api/v1/programs/{$this->program->id}/applications", applicationPayload($business->id))->assertCreated();
        $this->actingAs($applicant, 'sanctum')->postJson("/api/v1/programs/{$this->program->id}/applications", applicationPayload($business->id))
            ->assertStatus(409)->assertJson(['success' => false, 'message' => 'You already have an active application for this program.']);
    });

    it('rejects applications to an unpublished program', function () {
        $business = \App\Models\Business\Listing::create(['user_id' => $this->applicantUser->id, 'name' => 'GreenField Agri']);
        $this->program->update(['status' => 'draft']);
        $this->actingAsApplicant()->postJson("/api/v1/programs/{$this->program->id}/applications", applicationPayload($business->id))
            ->assertStatus(422)->assertJson(['success' => false, 'message' => 'Program is not open for applications.']);
    });

    it('requires the program owner to accept an application', function () {
        $this->actingAsApplicant()->postJson("/api/v1/programs/applications/{$this->application->id}/accept")
            ->assertStatus(403)->assertJson(['success' => false, 'message' => 'Unauthorized.']);
    });
});
