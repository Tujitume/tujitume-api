<?php

use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundCustomQuestion;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// The real path: the owner advances an applicant through the API, then both rounds are read, then the applicant submits.
describe('Round flow, end to end', function () {
    it('advance -> round 2 waits for the applicant -> they submit -> round 2 opens for review', function () {
        $this->round->update(['round_name' => 'Round one']);
        $round2 = ProgramRound::factory()->create([
            'program_id' => $this->program->id, 'round_number' => 2, 'round_name' => 'Round two', 'status' => 'published',
            'open_date' => now()->subDay(), 'close_date' => now()->addMonth(), 'advancement_mode' => 'manual',
        ]);
        $round3 = ProgramRound::factory()->create(['program_id' => $this->program->id, 'round_number' => 3, 'round_name' => 'Round three', 'status' => 'draft']);
        $this->program->update(['program_type' => 'multi_round', 'total_rounds' => 3]);
        $q1 = RoundCustomQuestion::create(['round_id' => $this->round->id, 'question_text' => 'R1 question', 'question_type' => 'short_answer', 'is_required' => false, 'display_order' => 1]);
        $q2 = RoundCustomQuestion::create(['round_id' => $round2->id, 'question_text' => 'R2 question', 'question_type' => 'short_answer', 'is_required' => false, 'display_order' => 1]);
        ApplicationRoundResponse::create(['application_id' => $this->application->id, 'round_id' => $this->round->id, 'question_id' => $q1->id, 'response' => 'R1 answer']);
        \App\Models\ApplicationRoundHistory::create(['application_id' => $this->application->id, 'round_id' => $this->round->id, 'round_number' => 1, 'entered_at' => now()->subDays(3), 'submitted_at' => now()->subDays(3), 'outcome' => 'in_progress']);

        // 1. the owner advances the applicant, through the real endpoint
        $this->actingAsOrgOwner()->postJson("/api/v1/programs/applications/{$this->application->id}/advance")->assertOk();

        // 2. round 2 lists them, awaiting submission, with nothing to read and no scoring
        $list = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$round2->id}/applications")->assertOk();
        $list->assertJsonPath('applications.0.id', $this->application->id)->assertJsonPath('applications.0.participation.state', 'awaiting_submission');
        $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$round2->id}/applications/{$this->application->id}")
            ->assertOk()->assertJsonPath('application.participation.submitted', false)->assertJsonCount(0, 'application.round_answers');
        $this->actingAsOrgOwner()->postJson("/api/v1/programs/applications/{$this->application->id}/scores", ['criterion_scores' => [['criterion' => 'Impact', 'score' => 5]]])->assertStatus(422);

        // the applicant sees round 1 as done, round 2 as theirs to do, and round 3 as not reached yet
        $mine = $this->actingAsApplicant()->getJson("/api/v1/programs/applications/{$this->application->id}/rounds")->assertOk();
        $mine->assertJsonPath('rounds.0.is_past', true)->assertJsonPath('rounds.1.is_current', true)->assertJsonPath('rounds.2.is_upcoming', true)
            ->assertJsonPath('journey.round_number', 2)->assertJsonPath('journey.state', 'in_progress');

        // 3. round 1 still shows what happened there; round 3 has nobody
        $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/applications/{$this->application->id}")
            ->assertOk()->assertJsonPath('application.participation.submitted', true)->assertJsonPath('application.round_status.value', 'advanced')
            ->assertJsonPath('application.round_answers.0.response', 'R1 answer');
        $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$round3->id}/applications")->assertOk()->assertJsonCount(0, 'applications');

        // 4. the applicant submits round 2 through the real endpoint
        $this->actingAsApplicant()->postJson("/api/v1/programs/applications/{$this->application->id}/current-round/submit", [])->assertOk();

        // 5. round 2 now has a submission to read and can be scored
        $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$round2->id}/applications/{$this->application->id}")
            ->assertOk()->assertJsonPath('application.participation.state', 'submitted')->assertJsonPath('application.participation.can_review', true);
        $this->actingAsOrgOwner()->postJson("/api/v1/programs/applications/{$this->application->id}/scores", ['criterion_scores' => [['criterion' => 'Impact', 'score' => 5]]])->assertSuccessful();
    });

    it('accepting an applicant out of round 1 records the advance on round 1', function () {
        $round2 = ProgramRound::factory()->create([
            'program_id' => $this->program->id, 'round_number' => 2, 'round_name' => 'Round two', 'status' => 'published',
            'open_date' => now()->subDay(), 'close_date' => now()->addMonth(), 'advancement_mode' => 'manual',
        ]);
        $this->program->update(['program_type' => 'multi_round', 'total_rounds' => 2]);
        \App\Models\ApplicationRoundHistory::create(['application_id' => $this->application->id, 'round_id' => $this->round->id, 'round_number' => 1, 'entered_at' => now()->subDays(3), 'submitted_at' => now()->subDays(3), 'outcome' => 'in_progress']);
        $this->application->update(['status' => 'pending']);

        $this->actingAsOrgOwner()->postJson("/api/v1/programs/applications/{$this->application->id}/accept")->assertOk();

        // round 1 now says the applicant advanced, in the list, in its counts and on the rounds overview
        $list = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/applications")->assertOk();
        $list->assertJsonPath('applications.0.round_outcome', 'advanced')->assertJsonPath('stats.advanced_count', 1)->assertJsonPath('stats.in_progress_count', 0);
        $overview = $this->actingAsOrgOwner()->getJson("/api/v1/programs/{$this->program->id}/rounds")->assertOk();
        $rounds = collect($overview->json('rounds') ?? $overview->json('data.rounds') ?? $overview->json('data'));
        expect($rounds->firstWhere('id', $this->round->id)['advanced_count'])->toBe(1);
        expect($rounds->firstWhere('id', $round2->id)['awaiting_submission_count'])->toBe(1);
    });

    it('shows an advance that an older version left open as advanced', function () {
        $round2 = ProgramRound::factory()->create(['program_id' => $this->program->id, 'round_number' => 2, 'status' => 'published']);
        $this->program->update(['program_type' => 'multi_round', 'total_rounds' => 2]);
        \App\Models\ApplicationRoundHistory::create(['application_id' => $this->application->id, 'round_id' => $this->round->id, 'round_number' => 1, 'entered_at' => now()->subDays(3), 'outcome' => 'in_progress']);
        \App\Models\ApplicationRoundHistory::create(['application_id' => $this->application->id, 'round_id' => $round2->id, 'round_number' => 2, 'entered_at' => now(), 'outcome' => 'in_progress']);
        $this->application->update(['current_round_id' => $round2->id, 'round_status' => 'draft']);

        $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/applications")->assertOk()
            ->assertJsonPath('applications.0.round_outcome', 'advanced')->assertJsonPath('stats.advanced_count', 1);
    });
});
