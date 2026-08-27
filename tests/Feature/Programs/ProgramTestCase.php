<?php

namespace Tests\Feature\Programs;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Organizations\Workspace;
use App\Models\Programs\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ProgramTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $orgUser;
    protected User $applicantUser;
    protected User $reviewerUser;
    protected Organization $organization;
    protected Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgUser = User::factory()->create(['user_type_id' => 4]);
        $this->organization = Organization::factory()->create(['owner_user_id' => $this->orgUser->id]);
        Workspace::factory()->create(['organization_id' => $this->organization->id]);
        $this->orgUser->update(['organization_id' => $this->organization->id]);
        $this->applicantUser = User::factory()->create(['user_type_id' => 1]);
        $this->reviewerUser = User::factory()->create(['user_type_id' => 6]);
        $this->program = Program::factory()->create(['user_id' => $this->orgUser->id]);
    }

    protected function actingAsOrgOwner(): static { return $this->actingAs($this->orgUser, 'sanctum'); }
    protected function actingAsApplicant(): static { return $this->actingAs($this->applicantUser, 'sanctum'); }
    protected function actingAsReviewer(): static { return $this->actingAs($this->reviewerUser, 'sanctum'); }
}
