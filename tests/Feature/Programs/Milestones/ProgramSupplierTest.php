<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Program suppliers', function () {
    it('requires authentication to create suppliers', function () {
        assertProgramUnauthenticated('POST', 'supplier-directory');
    });
    it('validates supplier input for an organization user', function () {
        $this->actingAsOrgOwner()->postJson('/api/v1/programs/supplier-directory', [])->assertStatus(422)->assertJson(['success' => false])->assertJsonStructure(['errors']);
    });
});
