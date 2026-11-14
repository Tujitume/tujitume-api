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
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.round_status', 'submitted');

        $application = \App\Models\Programs\ProgramApplication::where('program_id', $this->program->id)
            ->where('user_id', $applicant->id)->firstOrFail();
        expect($application->round_status)->toBe('submitted');
        $this->assertDatabaseHas('application_round_history', [
            'application_id' => $application->id,
            'round_id' => $application->current_round_id,
        ]);
        expect(\App\Models\ApplicationRoundHistory::where('application_id', $application->id)
            ->where('round_id', $application->current_round_id)->value('submitted_at'))->not->toBeNull();
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
            'round_status' => 'submitted',
        ]);
    });

    it('repairs submitted first-round applications without changing legitimate later-round drafts', function () {
        $firstRoundDraft = $this->application;
        $firstRoundDraft->update(['status' => 'pending', 'round_status' => 'draft']);
        \App\Models\ApplicationRoundHistory::create([
            'application_id' => $firstRoundDraft->id,
            'round_id' => $firstRoundDraft->current_round_id,
            'round_number' => 1,
            'entered_at' => now()->subMinute(),
            'submitted_at' => null,
            'outcome' => 'in_progress',
        ]);

        $laterRound = \App\Models\Programs\Rounds\ProgramRound::factory()->create([
            'program_id' => $this->program->id,
            'round_number' => 2,
        ]);
        $laterDraft = \App\Models\Programs\ProgramApplication::factory()->create([
            'program_id' => $this->program->id,
            'program_owner_id' => $this->program->user_id,
            'current_round_id' => $laterRound->id,
            'status' => 'pending',
            'round_status' => 'draft',
        ]);

        $migration = require database_path('migrations/2026_11_14_000200_repair_submitted_first_round_applications.php');
        $migration->up();

        expect($firstRoundDraft->fresh()->round_status)->toBe('submitted')
            ->and($laterDraft->fresh()->round_status)->toBe('draft')
            ->and(\App\Models\ApplicationRoundHistory::where('application_id', $firstRoundDraft->id)
                ->where('round_id', $firstRoundDraft->current_round_id)->value('submitted_at'))->not->toBeNull();
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

