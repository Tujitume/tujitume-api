<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Program wallet endpoints', function () {
    it('requires authentication to view a wallet', function () {
        assertProgramUnauthenticated('GET', "{$this->program->id}/wallets");
    });

    it('rejects a deposit payload missing required fields', function () {
        $this->actingAsOrgOwner()->postJson("/api/v1/programs/wallets/{$this->wallet->id}/deposit", [])
            ->assertStatus(422)->assertJson(['success' => false])->assertJsonStructure(['errors']);
    });
});
