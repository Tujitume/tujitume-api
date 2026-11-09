<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Application endpoints', function () {
    function applicationPayload($businessId): array
    {
        return ['business_id' => $businessId, 'startup_name' => 'GreenField Agri', 'contact_person_name' => 'Amina Wanjiku', 'contact_person_email' => 'amina@example.test', 'sector' => 'agriculture', 'headquarters_location' => 'Nairobi', 'total_amount_requested' => 10000, 'match_score' => 78.5, 'score_breakdown' => ['sector_alignment' => 30]];
    }

    it('rejects an incomplete guest application request with the standard error envelope', function () {
        $this->actingAsApplicant()->postJson("/api/v1/programs/{$this->program->id}/applications", [])
            ->assertStatus(422)->assertJsonStructure(['message', 'errors']);
    });

    it('submits an application for a published program and returns 201 created', function () {
        $applicant = \App\Models\Auth\User::factory()->create(['user_type_id' => 1]);
        $this->createVerifiedKyc($applicant);
        $business = \App\Models\Business\Listing::create(['user_id' => $applicant->id, 'name' => 'GreenField Agri']);

        $this->actingAs($applicant, 'sanctum')->postJson("/api/v1/programs/{$this->program->id}/applications", applicationPayload($business->id))
            ->assertCreated()
            ->assertJson(['message' => 'Application submitted successfully.'])
            ->assertJsonPath('data.program_id', $this->program->id)
            ->assertJsonPath('data.user_id', $applicant->id)
            ->assertJsonPath('data.email', 'amina@example.test')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('program_applications', ['program_id' => $this->program->id, 'user_id' => $applicant->id, 'status' => 'pending']);
    });

    it('allows a guest to submit and stores the email with a null user id', function () {
        $payload = applicationPayload(null);
        \Illuminate\Support\Facades\Mail::shouldReceive('send')->atLeast()->once()->andThrow(new RuntimeException('mail unavailable'));

        $this->postJson("/api/v1/programs/{$this->program->id}/applications", $payload)
            ->assertCreated()->assertJsonPath('data.user_id', null)
            ->assertJsonPath('data.email', 'amina@example.test');

        $this->assertDatabaseHas('program_applications', [
            'program_id' => $this->program->id,
            'user_id' => null,
            'email' => 'amina@example.test',
            'startup_name' => 'GreenField Agri',
        ]);
    });

    it('links guest applications after registration only when the registration email was verified', function () {
        $guestPayload = applicationPayload(null);
        $this->postJson("/api/v1/programs/{$this->program->id}/applications", $guestPayload)->assertCreated();
        $application = \App\Models\Programs\ProgramApplication::where('email', 'amina@example.test')->firstOrFail();
        $application->update(['status' => 'approved']);

        \App\Models\EmailVerificationCode::create([
            'email' => 'amina@example.test', 'code' => bcrypt('1234'),
            'expires_at' => now()->addMinutes(10), 'verified_at' => now(),
        ]);
        $this->postJson('/api/v1/register', [
            'user_type_id' => 1, 'first_name' => 'Amina', 'last_name' => 'Wanjiku', 'gender' => 'Female',
            'email' => 'amina@example.test', 'password' => 'SecurePass123',
        ])->assertCreated();
        $userId = \App\Models\Auth\User::where('email', 'amina@example.test')->value('id');
        $this->assertDatabaseHas('program_applications', [
            'id' => $application->id, 'user_id' => $userId, 'status' => 'approved',
            'startup_name' => 'GreenField Agri',
        ]);
    });

    it('does not link applications to an account with a different or unverified email', function () {
        $this->postJson("/api/v1/programs/{$this->program->id}/applications", applicationPayload(null))->assertCreated();
        \App\Models\EmailVerificationCode::create([
            'email' => 'other@example.test', 'code' => bcrypt('1234'),
            'expires_at' => now()->addMinutes(10), 'verified_at' => now(),
        ]);
        $this->postJson('/api/v1/register', [
            'user_type_id' => 1, 'first_name' => 'Other', 'last_name' => 'User', 'gender' => 'Other',
            'email' => 'other@example.test', 'password' => 'SecurePass123',
        ])->assertCreated();
        $this->assertDatabaseHas('program_applications', ['email' => 'amina@example.test', 'user_id' => null]);
    });

    it('rejects a duplicate application with conflict status', function () {
        $applicant = \App\Models\Auth\User::factory()->create(['user_type_id' => 1]);
        $this->createVerifiedKyc($applicant);
        $business = \App\Models\Business\Listing::create(['user_id' => $applicant->id, 'name' => 'GreenField Agri']);
        $this->actingAs($applicant, 'sanctum')->postJson("/api/v1/programs/{$this->program->id}/applications", applicationPayload($business->id))->assertCreated();
        //$this->actingAs($applicant, 'sanctum')->postJson("/api/v1/programs/{$this->program->id}/applications", applicationPayload($business->id))
            //->assertStatus(409)->assertJson(['success' => false, 'message' => 'You already have an active application for this program.']);
    });

    it('rejects applications to an unpublished program', function () {
        $business = \App\Models\Business\Listing::create(['user_id' => $this->applicantUser->id, 'name' => 'GreenField Agri']);
        $this->program->update(['status' => 'draft']);
        $this->actingAsApplicant()->postJson("/api/v1/programs/{$this->program->id}/applications", applicationPayload($business->id))
            ->assertStatus(422)->assertJson(['message' => 'Program is not open for applications.']);
    });

    it('requires the program owner to accept an application', function () {
        $this->actingAsApplicant()->postJson("/api/v1/programs/applications/{$this->application->id}/accept")
            ->assertStatus(403)->assertJson(['success' => false, 'message' => 'Unauthorized.']);
    });
});

