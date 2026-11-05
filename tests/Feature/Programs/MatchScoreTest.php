<?php

use App\Models\Programs\ProgramCustomField;
use App\Service\AiScore\MatchScore;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Program custom-field match scoring', function () {
    it('awards full credit for any overlapping multi-select target regardless of configured mode', function (string $mode) {
        $field = ProgramCustomField::create([
            'program_id' => $this->program->id,
            'key' => 'regions',
            'label' => 'Regions',
            'audience' => 'application',
            'type' => 'multi_select',
            'is_scoring' => true,
            'scoring' => [
                'enabled' => true,
                'weight' => 20,
                'match_type' => 'in_set',
                'target' => ['ny', 'ky', 'py'],
                'mode' => $mode,
            ],
        ]);

        $result = app(MatchScore::class)->compute($this->program, [
            $field->key => ['ny'],
        ]);

        expect($result['score'])->toBe(100)
            ->and($result['breakdown'][0]['match'])->toBe(1.0)
            ->and($result['breakdown'][0]['earned_points'])->toBe(100.0);
    })->with(['any', 'proportional', 'all']);

    it('awards full credit when the applicant selects one matching place and one non-target place', function () {
        $field = ProgramCustomField::create([
            'program_id' => $this->program->id,
            'key' => 'interested-places',
            'label' => 'Interested places',
            'audience' => 'application',
            'type' => 'multi_select',
            'is_scoring' => true,
            'scoring' => [
                'enabled' => true,
                'weight' => 20,
                'match_type' => 'in_set',
                'target' => ['Niagara', 'London'],
                'mode' => 'proportional',
            ],
        ]);

        $result = app(MatchScore::class)->compute($this->program, [
            $field->key => ['Niagara', 'NY'],
        ]);

        expect($result['score'])->toBe(100)
            ->and($result['breakdown'][0]['match'])->toBe(1.0);
    });

    it('awards no credit when a multi-select answer has no target overlap', function () {
        $field = ProgramCustomField::create([
            'program_id' => $this->program->id,
            'key' => 'regions',
            'label' => 'Regions',
            'audience' => 'application',
            'type' => 'multi_select',
            'is_scoring' => true,
            'scoring' => [
                'enabled' => true,
                'weight' => 20,
                'match_type' => 'in_set',
                'target' => ['ny', 'ky', 'py'],
                'mode' => 'proportional',
            ],
        ]);

        $result = app(MatchScore::class)->compute($this->program, [
            $field->key => ['london'],
        ]);

        expect($result['score'])->toBe(0)
            ->and($result['breakdown'][0]['match'])->toBe(0.0);
    });

    it('reports an overlapping set as matched in the applicant preview', function () {
        ProgramCustomField::create([
            'program_id' => $this->program->id,
            'key' => 'regions',
            'label' => 'Regions',
            'audience' => 'application',
            'type' => 'multi_select',
            'is_scoring' => true,
            'scoring' => [
                'enabled' => true,
                'weight' => 20,
                'match_type' => 'in_set',
                'target' => ['ny', 'ky', 'py'],
                'mode' => 'proportional',
            ],
        ]);

        $this->actingAsApplicant()
            ->postJson("/api/v1/programs/match-score/{$this->program->id}", [
                'custom_answers' => ['regions' => ['ny']],
            ])
            ->assertOk()
            ->assertJsonPath('data.match_score', 100)
            ->assertJsonPath('data.breakdown_for_applicant.0.status', 'matched')
            ->assertJsonMissingPath('data.breakdown_for_applicant.0.weight')
            ->assertJsonMissingPath('data.breakdown_for_applicant.0.target');
    });
});
