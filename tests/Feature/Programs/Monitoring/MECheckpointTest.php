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

describe('Assigned checkpoints (a team member\'s requests)', function () {
    it('lists only what was sent to the organization and assigned to the signed-in member, and counts what is still to do', function () {
        $member = \App\Models\Auth\User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
        $mine = \App\Models\Programs\Monitoring\MECheckpoint::create([
            'app_id' => $this->application->id, 'program_id' => $this->program->id, 'checkpoint_name' => 'Visit the farm', 'type' => 'monitoring',
            'respondent' => 'organization', 'assigned_to' => $member->id, 'sent_at' => now(), 'status' => 'pending', 'require_site_visit' => true,
        ]);
        // not sent yet, someone else's, and one already handed in: none of these is a request for them to act on now
        \App\Models\Programs\Monitoring\MECheckpoint::create(['app_id' => $this->application->id, 'program_id' => $this->program->id, 'checkpoint_name' => 'Unsent', 'type' => 'monitoring', 'respondent' => 'organization', 'assigned_to' => $member->id, 'sent_at' => null]);
        \App\Models\Programs\Monitoring\MECheckpoint::create(['app_id' => $this->application->id, 'program_id' => $this->program->id, 'checkpoint_name' => 'Not mine', 'type' => 'monitoring', 'respondent' => 'organization', 'assigned_to' => $this->orgUser->id, 'sent_at' => now()]);
        \App\Models\Programs\Monitoring\MECheckpoint::create(['app_id' => $this->application->id, 'program_id' => $this->program->id, 'checkpoint_name' => 'Done', 'type' => 'monitoring', 'respondent' => 'organization', 'assigned_to' => $member->id, 'sent_at' => now(), 'status' => 'submitted']);

        $list = $this->actingAs($member, 'sanctum')->getJson('/api/v1/programs/monitoring/assigned-checkpoints')->assertOk();
        $names = collect($list->json('data.checkpoints'))->pluck('checkpoint_name')->sort()->values()->all();
        expect($names)->toBe(['Done', 'Visit the farm']);
        expect(collect($list->json('data.checkpoints'))->firstWhere('id', $mine->id)['needs_action'])->toBeTrue();
        expect(collect($list->json('data.checkpoints'))->firstWhere('id', $mine->id)['program_title'])->toBe($this->program->program_title);

        $this->actingAs($member, 'sanctum')->getJson('/api/v1/programs/reviewer/assignment-counts')->assertOk()
            ->assertJsonPath('assigned_checkpoints', 1)->assertJsonPath('total', 1);
    });
});
