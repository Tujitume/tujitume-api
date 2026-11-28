<?php

use App\Models\Auth\User;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// ISSUE-38 (e2e test 2026-11-27): programs created by a team member were missing from the owner's Applications overview,
// dashboard counts and lists, because they were selected by the organization owner's user id.
beforeEach(function () {
    $this->member = User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
    $this->memberProgram = Program::factory()->create(['user_id' => $this->member->id, 'organization_id' => $this->organization->id, 'status' => 'published', 'program_title' => 'Team program']);
    ProgramApplication::factory()->create(['program_id' => $this->memberProgram->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->member->id, 'status' => 'pending']);
});

it('lists every program of the organization, whoever on the team created it', function () {
    expect(Program::ofOrganization($this->orgUser)->pluck('id')->all())->toContain($this->program->id, $this->memberProgram->id)
        ->and(Program::ofOrganization($this->member)->pluck('id')->all())->toContain($this->program->id, $this->memberProgram->id);
    $outsider = User::factory()->create(['user_type_id' => 4]);
    expect(Program::ofOrganization($outsider)->pluck('id')->all())->toBe([]);
});

it('shows a team member\'s program on the owner\'s Applications overview', function () {
    $res = $this->actingAs($this->orgUser, 'sanctum')->getJson('/api/v1/programs/applications-overview')->assertOk();
    $titles = collect($res->json('programs'))->pluck('program_title');
    expect($titles)->toContain('Team program');
});

it('lets the organization owner act on a program a team member created', function () {
    $this->actingAs($this->orgUser, 'sanctum');
    expect(\App\Service\Program\ProgramAccess::owns($this->memberProgram))->toBeTrue();
});

// ISSUE-41: one real status per program, and "to review" only for applications still waiting for a decision
it('shows a program whose rounds stopped taking applications as under review, and counts only undecided applications', function () {
    $this->round->update(['status' => 'published', 'open_date' => now()->subDays(20), 'close_date' => now()->subDay(), 'opens_at' => now()->subDays(20), 'closes_at' => now()->subDay()]);
    $this->application->update(['status' => 'pending', 'round_status' => 'assigned']);
    ProgramApplication::factory()->create(['program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id,
        'current_round_id' => $this->round->id, 'status' => 'rejected', 'round_status' => 'not_selected']);

    $row = collect($this->actingAs($this->orgUser, 'sanctum')->getJson('/api/v1/programs/applications-overview')->json('programs'))->firstWhere('id', $this->program->id);
    expect($row['status']['value'])->toBe('under_review')->and($row['to_review_count'])->toBe(1);

    $this->round->update(['status' => 'finalized']);
    $this->program->update(['total_rounds' => 1]);
    $row = collect($this->actingAs($this->orgUser, 'sanctum')->getJson('/api/v1/programs/applications-overview')->json('programs'))->firstWhere('id', $this->program->id);
    expect($row['status']['value'])->toBe('closed')->and($row['to_review_count'])->toBe(0);
});

it('shows the current round\'s deadline for a multi-round program (ISSUE-41)', function () {
    $this->round->update(['status' => 'finalized', 'close_date' => now()->subDays(5)->toDateString(), 'closes_at' => now()->subDays(5)]);
    \App\Models\Programs\Rounds\ProgramRound::factory()->create(['program_id' => $this->program->id, 'round_number' => 2, 'status' => 'published',
        'open_date' => now()->subDay()->toDateString(), 'close_date' => now()->addDays(10)->toDateString(), 'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(10)]);
    $this->program->update(['total_rounds' => 2]);

    $row = collect($this->actingAs($this->orgUser, 'sanctum')->getJson('/api/v1/programs/applications-overview')->json('programs'))->firstWhere('id', $this->program->id);
    expect($row['status']['value'])->toBe('open')
        ->and(\Illuminate\Support\Carbon::parse($row['closes_at'])->isFuture())->toBeTrue();
});
