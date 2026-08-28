<?php

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
});
