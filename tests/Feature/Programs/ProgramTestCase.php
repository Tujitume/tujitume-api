<?php

namespace Tests\Feature\Programs;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Organizations\Workspace;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramWallet;
use App\Models\Programs\Rounds\ProgramRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

abstract class ProgramTestCase extends TestCase
{
    use RefreshDatabase;

    public User $orgUser;

    public User $applicantUser;

    public User $reviewerUser;

    public Organization $organization;

    public Workspace $workspace;

    public Program $program;

    public ProgramRound $round;

    public ProgramApplication $application;

    public ProgramWallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');

        $this->orgUser = User::factory()->create(['user_type_id' => 4]);
        $this->organization = Organization::factory()->create(['owner_user_id' => $this->orgUser->id]);
        $this->workspace = Workspace::factory()->create(['organization_id' => $this->organization->id]);
        $this->orgUser->update(['organization_id' => $this->organization->id]);
        $this->applicantUser = User::factory()->create(['user_type_id' => 1]);
        $this->reviewerUser = User::factory()->create(['user_type_id' => 6]);
        $this->program = Program::factory()->create(['user_id' => $this->orgUser->id, 'status' => 'published']);
        $this->wallet = ProgramWallet::factory()->create(['program_id' => $this->program->id, 'status' => 'active', 'balance' => 100000]);
        $this->round = ProgramRound::factory()->create(['program_id' => $this->program->id, 'round_number' => 1, 'status' => 'published', 'advancement_mode' => 'manual']);
        $this->application = ProgramApplication::factory()->create(['program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $this->round->id, 'status' => 'pending', 'round_status' => 'submitted']);
    }

    protected function actingAsOrgOwner(): static
    {
        return $this->actingAs($this->orgUser, 'sanctum');
    }

    protected function actingAsApplicant(): static
    {
        return $this->actingAs($this->applicantUser, 'sanctum');
    }

    protected function actingAsReviewer(): static
    {
        return $this->actingAs($this->reviewerUser, 'sanctum');
    }

    protected function actingAsAdmin(): static
    {
        return $this->actingAs(User::factory()->create(['user_type_id' => 5]), 'sanctum');
    }
}
