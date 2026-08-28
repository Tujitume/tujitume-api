<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Program email template CRUD', function () {
    it('requires authentication to list templates', function () {
        assertProgramUnauthenticated('GET', "{$this->program->id}/email-templates");
    });

    it('allows an owner to store, get, and delete an email template', function () {
        $event = 'application.accepted';
        $this->actingAsOrgOwner()->patchJson("/api/v1/programs/{$this->program->id}/email-templates/{$event}", ['event' => $event, 'body_html' => '<p>Congratulations.</p>'])
            ->assertOk()->assertJson(['success' => true, 'data' => ['template' => ['event' => $event]]]);
        $this->actingAsOrgOwner()->getJson("/api/v1/programs/{$this->program->id}/email-templates/{$event}")
            ->assertOk()->assertJson(['event' => $event, 'body_html' => '<p>Congratulations.</p>']);
        $this->actingAsOrgOwner()->deleteJson("/api/v1/programs/{$this->program->id}/email-templates/{$event}")
            ->assertOk()->assertJson(['success' => true]);
    });

    it('forbids another organization owner', function () {
        $otherOwner = \App\Models\Auth\User::factory()->create(['user_type_id' => 4]);
        $this->actingAs($otherOwner, 'sanctum')->getJson("/api/v1/programs/{$this->program->id}/email-templates")
            ->assertForbidden();
    });
});
