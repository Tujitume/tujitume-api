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

    it('allows the program owner to store, update, get, and delete an unused round', function () {
        // The shared fixture already occupies round one and has an application.
        // Create a second round so this test can also prove the delete path.
        $this->program->update(['total_rounds' => 3]);

        $created = $this->actingAsOrgOwner()
            ->postJson("/api/v1/programs/{$this->program->id}/rounds", [ 
                'round_name' => 'Due diligence',
                'open_date' => '2026-09-01',
                'close_date' => '2026-09-15',
                'review_period_end' => '2026-09-20',
                'announcement_date' => '2026-09-25',
                'rubric_mode' => 'weighted',
                'advancement_mode' => 'manual',
                'scoring_criteria' => [
                    [
                        'criteria_name' => 'Team',
                        'weight' => 0.5,
                        'description' => 'The team is strong and capable.',
                    ],
                    [
                        'criteria_name' => 'Market',
                        'weight' => 0.5,
                        'description' => 'The market is large and growing.',
                    ]
                ]
            ]);

        $created->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.round_name', 'Due diligence');

        $roundId = $created->json('data.id');

        $this->actingAsOrgOwner()
            ->patchJson("/api/v1/programs/rounds/{$roundId}", ['round_name' => 'Final due diligence'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.round_name', 'Final due diligence');

        $this->actingAsOrgOwner()
            ->getJson("/api/v1/programs/rounds/{$roundId}")
            ->assertOk()
            ->assertJsonPath('data.round_name', 'Final due diligence');

        $this->actingAsOrgOwner()
            ->deleteJson("/api/v1/programs/rounds/{$roundId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Round deleted successfully');

        $this->assertDatabaseMissing('program_rounds', ['id' => $roundId]);
    });

    it('saves questions sent with a new round as the round\'s questions', function () {
        $this->program->update(['total_rounds' => 3]);

        $created = $this->actingAsOrgOwner()
            ->postJson("/api/v1/programs/{$this->program->id}/rounds", [
                'round_name' => 'Full plan',
                'open_date' => '2026-09-01',
                'close_date' => '2026-09-15',
                'round_questions' => [
                    ['question_text' => 'Upload your business plan.', 'question_type' => 'file_upload', 'is_required' => true],
                    ['question_text' => 'What changed since round 1?', 'question_type' => 'long_text'],
                ],
            ]);

        $created->assertCreated()->assertJsonPath('data.round_name', 'Full plan');
        $roundId = $created->json('data.id');
        $this->assertDatabaseHas('round_custom_questions', ['round_id' => $roundId, 'question_text' => 'Upload your business plan.', 'display_order' => 1]);
        $this->assertDatabaseHas('round_custom_questions', ['round_id' => $roundId, 'question_text' => 'What changed since round 1?', 'display_order' => 2]);
    });
});
