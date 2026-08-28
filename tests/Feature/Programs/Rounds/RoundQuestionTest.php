<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Round questions and answers', function () {
    it('requires authentication to create questions', function () {
        assertProgramUnauthenticated('POST', "rounds/{$this->round->id}/questions");
    });
    it('validates a question payload for the owner', function () {
        $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/questions", [])->assertStatus(422)->assertJson(['success' => false])->assertJsonStructure(['errors']);
    });
});
