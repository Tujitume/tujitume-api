<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Round endpoints', function () {
    it('requires authentication to list rounds', function () {
        assertProgramUnauthenticated('GET', "{$this->program->id}/rounds");
    });

    it('returns validation errors when an owner creates an incomplete round', function () {
        $this->actingAsOrgOwner()->postJson("/api/v1/programs/{$this->program->id}/rounds", [])
            ->assertStatus(422)->assertJson(['success' => false])->assertJsonStructure(['errors']);
    });
});
