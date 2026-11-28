<?php

use App\Models\Auth\User;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// GET /organizations/{id}: the organization's profile for its own people (Settings > Profile, the coach dashboard)
it('shows the organization to its owner and members, never its wallet', function () {
    $this->actingAsOrgOwner()->getJson("/api/v1/organizations/{$this->organization->id}")->assertOk()
        ->assertJsonPath('data.id', $this->organization->id)
        ->assertJsonMissingPath('data.lipr_wallet')
        ->assertJsonMissingPath('data.stripe_account_id');

    $member = User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
    $this->actingAs($member, 'sanctum')->getJson("/api/v1/organizations/{$this->organization->id}")->assertOk();
});

it('keeps other people out', function () {
    $this->actingAsApplicant()->getJson("/api/v1/organizations/{$this->organization->id}")->assertForbidden();
});

it('leaves the named organization routes alone', function () {
    $this->actingAsOrgOwner()->getJson('/api/v1/organizations/audit-logs')->assertOk();
});
