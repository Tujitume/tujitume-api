<?php

use App\Support\ApiContract;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Program read endpoints follow the response contract', function () {
    it('lists the owner\'s programs in the envelope and keeps the legacy "programs" key', function () {
        $response = $this->actingAsOrgOwner()->getJson('/api/v1/programs/programs')->assertOk()
            ->assertJson(['success' => true, 'message' => 'Programs fetched successfully.'])
            ->assertJsonStructure(['data' => ['items'], 'programs']);

        expect($response->json('data.items'))->toHaveCount(count($response->json('programs')));
        expect($response->json('data'))->not->toHaveKey('pagination');
    });

    it('returns status as value, label and a semantic colour', function () {
        $status = $this->actingAsOrgOwner()->getJson('/api/v1/programs/programs')->json('data.items.0.status');

        expect($status)->toHaveKeys(['value', 'label', 'color']);
        expect($status['value'])->toBe('published');
        expect($status['label'])->toBe('Published');
        expect($status['color'])->toBeIn(['success', 'warning', 'danger', 'info', 'neutral']);
    });

    it('pages the list only when asked, with the pagination block', function () {
        $response = $this->actingAsOrgOwner()->getJson('/api/v1/programs/programs?page=1&per_page=1')->assertOk();

        expect($response->json('data.items'))->toHaveCount(1);
        expect($response->json('data.pagination'))->toHaveKeys(['current_page', 'per_page', 'total', 'last_page', 'from', 'to']);
        expect($response->json('data.pagination.per_page'))->toBe(1);
    });

    it('caps per_page at 100', function () {
        $this->actingAsOrgOwner()->getJson('/api/v1/programs/programs?per_page=5000')
            ->assertOk()->assertJsonPath('data.pagination.per_page', ApiContract::MAX_PER_PAGE);
    });

    it('serves one program in the envelope and keeps program_data', function () {
        $this->actingAsApplicant()->getJson("/api/v1/programs/get_program/{$this->program->id}")->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.program.id', $this->program->id)
            ->assertJsonPath('program_data.id', $this->program->id)
            ->assertJsonStructure(['data' => ['application_round'], 'application_round']);
    });

    it('answers an unknown program with success=false and 404', function () {
        $this->actingAsApplicant()->getJson('/api/v1/programs/get_program/999999')
            ->assertNotFound()->assertJson(['success' => false]);
    });

    it('gives each round a real status colour (regression: the config key lacked a dot)', function () {
        $round = $this->actingAsOrgOwner()->getJson("/api/v1/programs/{$this->program->id}/rounds")->assertOk()
            ->assertJsonStructure(['data' => ['items'], 'rounds'])->json('data.items.0');

        expect($round['status']['value'])->toBe($this->round->status);
        expect($round['status']['color'])->toBe(config('status.program_round.' . $this->round->status));
    });
});

describe('Status colours', function () {
    it('only use colours the frontend badge understands', function () {
        $allowed = ['success', 'warning', 'danger', 'info', 'neutral', 'gray'];

        foreach (config('status') as $group => $colors) {
            foreach ($colors as $value => $color) {
                expect($color)->toBeIn($allowed, "status.{$group}.{$value} uses an unknown colour");
            }
        }
    });

    it('turns an unknown status or the old "gray" into neutral', function () {
        expect(ApiContract::statusMeta('program', 'something_new')['color'])->toBe('neutral');
        expect(ApiContract::statusMeta('program', 'open'))->toBe(['value' => 'open', 'label' => 'Open', 'color' => 'success']);
    });
});
