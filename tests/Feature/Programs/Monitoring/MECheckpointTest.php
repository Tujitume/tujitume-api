<?php

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Programs\Monitoring\MECheckpoint;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Monitoring checkpoints', function () {
    it('requires authentication to create a checkpoint', function () {
        assertProgramUnauthenticated('POST', "monitoring/applications/{$this->application->id}/checkpoints");
    });
    it('validates checkpoint fields for the owner', function () {
        $this->actingAsOrgOwner()->postJson("/api/v1/programs/monitoring/applications/{$this->application->id}/checkpoints", [])->assertStatus(422)->assertJson(['success' => false])->assertJsonStructure(['errors']);
    });

    it('allows the owner to store, update, list, and delete a checkpoint', function () {
        $payload = ['checkpoint_name' => 'Quarterly impact report', 'type' => 'monitoring', 'due_date' => now()->addMonth()->toDateString(), 'require_site_visit' => false, 'meeting_required' => false, 'should_notify_applicant' => false, 'display_order' => 1];

        $created = $this->actingAsOrgOwner()->postJson("/api/v1/programs/monitoring/applications/{$this->application->id}/checkpoints", $payload)
            ->assertCreated()->assertJson(['success' => true])->json('data');

        $checkpointId = $created['id'];
        $this->actingAsOrgOwner()->patchJson("/api/v1/programs/monitoring/checkpoints/{$checkpointId}", ['checkpoint_name' => 'Updated impact report'])
            ->assertOk()->assertJson(['success' => true, 'data' => ['checkpoint_name' => 'Updated impact report']]);
        $this->actingAsApplicant()->getJson("/api/v1/programs/monitoring/applications/{$this->application->id}/checkpoints")
            ->assertOk()->assertJsonStructure(['success', 'data']);
        $this->actingAsOrgOwner()->deleteJson("/api/v1/programs/monitoring/checkpoints/{$checkpointId}")
            ->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseMissing('m_e_checkpoints', ['id' => $checkpointId]);
    });

    it('assigns organization checkpoints to active organization members and only lets the assignee or owner submit', function () {
        $member = User::factory()->create([
            'user_type_id' => 4,
            'organization_id' => $this->organization->id,
        ]);
        $role = Role::create(['name' => 'Monitoring team member', 'access_types' => []]);
        OrganizationUserRole::create([
            'organization_id' => $this->organization->id,
            'user_id' => $member->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $checkpoint = MECheckpoint::create([
            'app_id' => $this->application->id,
            'program_id' => $this->program->id,
            'checkpoint_name' => 'Organization monitoring report',
            'type' => 'monitoring',
            'respondent' => 'organization',
            'status' => 'pending',
            'display_order' => 1,
        ]);

        $this->actingAsOrgOwner()
            ->postJson("/api/v1/programs/monitoring/checkpoints/{$checkpoint->id}/send", ['assigned_to' => $member->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_to.id', $member->id)
            ->assertJsonPath('data.assigned_to.name', trim($member->first_name . ' ' . $member->last_name));

        $this->assertDatabaseHas('notifications', ['receiver_id' => $member->id]);

        $this->actingAsApplicant()
            ->postJson("/api/v1/programs/monitoring/checkpoints/{$checkpoint->id}/submit", ['written_report' => 'Not allowed'])
            ->assertForbidden();

        $this->actingAsOrgOwner()
            ->postJson("/api/v1/programs/monitoring/checkpoints/{$checkpoint->id}/submit", ['written_report' => 'Completed by owner'])
            ->assertCreated();

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/v1/programs/monitoring/applications/{$this->application->id}/checkpoints")
            ->assertOk()
            ->assertJsonPath('data.checkpoints.0.assigned_to.id', $member->id);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/programs/monitoring/checkpoints/{$checkpoint->id}/submit", ['written_report' => 'Completed by assignee'])
            ->assertCreated();
    });

    it('rejects assignment to someone outside the program organization', function () {
        $otherMember = User::factory()->create(['user_type_id' => 4]);
        $checkpoint = MECheckpoint::create([
            'app_id' => $this->application->id,
            'program_id' => $this->program->id,
            'checkpoint_name' => 'Organization monitoring report',
            'type' => 'monitoring',
            'respondent' => 'organization',
            'status' => 'pending',
            'display_order' => 1,
        ]);

        $this->actingAsOrgOwner()
            ->postJson("/api/v1/programs/monitoring/checkpoints/{$checkpoint->id}/send", ['assigned_to' => $otherMember->id])
            ->assertUnprocessable();

        $this->assertDatabaseHas('m_e_checkpoints', ['id' => $checkpoint->id, 'sent_at' => null, 'assigned_to' => null]);
    });
});
