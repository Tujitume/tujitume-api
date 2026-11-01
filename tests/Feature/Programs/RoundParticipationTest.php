<?php

use App\Models\ApplicationRoundHistory;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundCustomQuestion;

uses(Tests\Feature\Programs\ProgramTestCase::class);

/*
 * An applicant advanced from round 1 to round 2 is placed in round 2 with nothing handed in. Until they submit it the
 * team sees round 2's setup and why there is nothing to read, never saved drafts, and cannot score them. Round 1 keeps
 * showing what happened in round 1.
 */
describe('Round participation', function () {
    beforeEach(function () {
        $this->round->update(['round_name' => 'Round one']);
        $this->round2 = ProgramRound::factory()->create([
            'program_id' => $this->program->id, 'round_number' => 2, 'round_name' => 'Round two', 'status' => 'published',
            'open_date' => now()->subDay(), 'close_date' => now()->addMonth(),
        ]);
        $this->round3 = ProgramRound::factory()->create([
            'program_id' => $this->program->id, 'round_number' => 3, 'round_name' => 'Round three', 'status' => 'draft',
        ]);

        $this->q1 = RoundCustomQuestion::create(['round_id' => $this->round->id, 'question_text' => 'Round one question', 'question_type' => 'short_answer', 'is_required' => true, 'display_order' => 1]);
        $this->q2 = RoundCustomQuestion::create(['round_id' => $this->round2->id, 'question_text' => 'Round two question', 'question_type' => 'short_answer', 'is_required' => true, 'display_order' => 1]);

        // Round 1: answered, scored and advanced
        ApplicationRoundResponse::create(['application_id' => $this->application->id, 'round_id' => $this->round->id, 'question_id' => $this->q1->id, 'response' => 'Round one answer']);
        ApplicationScore::factory()->create(['application_id' => $this->application->id, 'round_id' => $this->round->id, 'reviewer_id' => $this->orgUser->id]);
        ApplicationRoundHistory::create(['application_id' => $this->application->id, 'round_id' => $this->round->id, 'round_number' => 1, 'entered_at' => now()->subDays(5), 'submitted_at' => now()->subDays(5), 'exited_at' => now()->subDay(), 'outcome' => 'advanced', 'average_score' => 80]);

        // Advanced into round 2: placed there, nothing submitted, one answer saved as a draft
        ApplicationRoundHistory::create(['application_id' => $this->application->id, 'round_id' => $this->round2->id, 'round_number' => 2, 'entered_at' => now()->subDay(), 'outcome' => 'in_progress']);
        $this->application->update(['current_round_id' => $this->round2->id, 'round_status' => 'draft']);
        ApplicationRoundResponse::create(['application_id' => $this->application->id, 'round_id' => $this->round2->id, 'question_id' => $this->q2->id, 'response' => 'Saved but not submitted']);
    });

    it('shows round two as awaiting submission, with its setup but no saved answers', function () {
        $response = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round2->id}/applications/{$this->application->id}")->assertOk();

        $response->assertJsonPath('application.participation.state', 'awaiting_submission')
            ->assertJsonPath('application.participation.submitted', false)
            ->assertJsonPath('application.participation.can_review', false)
            ->assertJsonPath('application.current_round.id', $this->round2->id)
            ->assertJsonPath('application.current_round.questions.0.question_text', 'Round two question')
            ->assertJsonPath('application.round_status.value', 'draft')
            ->assertJsonCount(0, 'application.round_answers')
            ->assertJsonCount(0, 'application.scores');
        expect($response->json('application.participation.reason'))->toContain('has not submitted');
    });

    it('says why when the round has not opened yet', function () {
        $this->round2->update(['status' => 'draft', 'open_date' => now()->addWeek()]);

        $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round2->id}/applications/{$this->application->id}")
            ->assertOk()->assertJsonPath('application.participation.state', 'not_open');
    });

    it('says so when the round closed without a submission', function () {
        $this->round2->update(['status' => 'closed', 'close_date' => now()->subDay()]);

        $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round2->id}/applications/{$this->application->id}")
            ->assertOk()->assertJsonPath('application.participation.state', 'missed');
    });

    it('shows round two answers once the applicant submits it', function () {
        $this->application->update(['round_status' => 'submitted']);
        ApplicationRoundHistory::where('application_id', $this->application->id)->where('round_id', $this->round2->id)->update(['submitted_at' => now()]);

        $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round2->id}/applications/{$this->application->id}")
            ->assertOk()
            ->assertJsonPath('application.participation.state', 'submitted')
            ->assertJsonPath('application.participation.can_review', true)
            ->assertJsonPath('application.round_answers.0.response', 'Saved but not submitted');
    });

    it('keeps showing round one as it happened, with its answers, score and decision', function () {
        $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/applications/{$this->application->id}")
            ->assertOk()
            ->assertJsonPath('application.participation.state', 'submitted')
            ->assertJsonPath('application.participation.is_current', false)
            ->assertJsonPath('application.current_round.id', $this->round->id)
            ->assertJsonPath('application.round_answers.0.response', 'Round one answer')
            ->assertJsonCount(1, 'application.scores')
            ->assertJsonPath('application.round_status.value', 'advanced')
            ->assertJsonPath('application.knockout_status.value', 'passed');
    });

    it('has nobody in a round nobody was advanced into', function () {
        $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round3->id}/applications")
            ->assertOk()->assertJsonPath('stats.total', 0)->assertJsonCount(0, 'applications');

        $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round3->id}/applications/{$this->application->id}")
            ->assertNotFound()->assertJsonPath('participation.state', 'not_in_round');
    });

    it('lists the advanced applicant in round two, marked as awaiting submission', function () {
        $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round2->id}/applications")
            ->assertOk()
            ->assertJsonPath('applications.0.id', $this->application->id)
            ->assertJsonPath('applications.0.participation.state', 'awaiting_submission')
            ->assertJsonPath('applications.0.review_summary.reviewers_scored', 0);
    });

    it('does not let round two be scored before the applicant submits it', function () {
        $this->actingAsOrgOwner()->postJson("/api/v1/programs/applications/{$this->application->id}/scores", [
            'criterion_scores' => [['criterion' => 'Impact', 'score' => 8]],
        ])->assertStatus(422)->assertJsonPath('success', false)
            ->assertJsonFragment(['message' => 'This applicant has not submitted Round two yet, so it cannot be scored. You can score it once they submit.']);

        expect(ApplicationScore::where('application_id', $this->application->id)->where('round_id', $this->round2->id)->count())->toBe(0);
    });

    it('scores round two once the applicant has submitted it', function () {
        $this->application->update(['round_status' => 'submitted']);
        ApplicationRoundHistory::where('application_id', $this->application->id)->where('round_id', $this->round2->id)->update(['submitted_at' => now()]);

        $this->actingAsOrgOwner()->postJson("/api/v1/programs/applications/{$this->application->id}/scores", [
            'criterion_scores' => [['criterion' => 'Impact', 'score' => 8]],
        ])->assertSuccessful();
    });

    it('counts everyone who took part in each round, and who is still to submit', function () {
        $rounds = collect($this->actingAsOrgOwner()->getJson("/api/v1/programs/{$this->program->id}/rounds")->assertOk()->json('data.rounds') ?? $this->actingAsOrgOwner()->getJson("/api/v1/programs/{$this->program->id}/rounds")->json('rounds'))->keyBy('round_number');

        expect($rounds[1]['applications_count'])->toBe(1);          // still counted in round 1 after advancing
        expect($rounds[2]['applications_count'])->toBe(1);          // placed in round 2
        expect($rounds[2]['awaiting_submission_count'])->toBe(1);   // and has not submitted it
        expect($rounds[3]['applications_count'])->toBe(0);          // nobody reached round 3
    });
});
