<?php

use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Service\Program\RoundMatchScore;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// The apply form no longer asks revenue, team experience, traction, stage or impact areas, and the score is built only
// from Round 1 questions. A question the applicant has not answered must not pull their score down.
describe('Match score with missing answers', function () {
    beforeEach(function () {
        $this->program->update(['use_match_score' => true, 'match_score_limit' => null]);
        $make = fn (string $text, int $points, int $order) => RoundCustomQuestion::create([
            'round_id' => $this->round->id, 'question_text' => $text, 'question_type' => 'yes_no',
            'is_required' => true, 'display_order' => $order, 'use_in_match_score' => true,
            'match_points' => $points, 'match_answers' => ['yes'],
        ]);
        $this->registered = $make('Is the business registered?', 60, 1);
        $this->women = $make('Is it women-led?', 40, 2);
    });

    it('scores a complete set of answers as the plain sum, as before', function () {
        $result = app(RoundMatchScore::class)->score($this->program->fresh(), $this->round, [
            $this->registered->id => 'yes', $this->women->id => 'no',
        ]);
        expect($result['match_score'])->toBe(60.0)
            ->and(collect($result['breakdown'])->pluck('counted')->all())->toBe([true, true]);
    });

    it('leaves an unanswered question out and scales over the answered ones', function () {
        $result = app(RoundMatchScore::class)->score($this->program->fresh(), $this->round, [$this->registered->id => 'yes']);

        // 60 of the 60 answered points -> the full 100, not 60
        expect($result['match_score'])->toBe(100.0)
            ->and(collect($result['breakdown'])->firstWhere('question_id', $this->women->id))
            ->toMatchArray(['status' => 'not answered', 'counted' => false, 'earned_points' => 0.0]);

        // a wrong answer still counts: it is an answer
        expect(app(RoundMatchScore::class)->score($this->program->fresh(), $this->round, [$this->registered->id => 'no'])['match_score'])->toBe(0.0);
    });

    it('gives no score, not zero, when nothing scored was answered', function () {
        $result = app(RoundMatchScore::class)->score($this->program->fresh(), $this->round, []);
        expect($result['match_score'])->toBeNull()->and($result['below_threshold'])->toBeFalse();
    });

    it('does not lower an existing application when the owner adds a scored question later', function () {
        ApplicationRoundResponse::create(['application_id' => $this->application->id, 'round_id' => $this->round->id, 'question_id' => $this->registered->id, 'response' => 'yes']);
        ApplicationRoundResponse::create(['application_id' => $this->application->id, 'round_id' => $this->round->id, 'question_id' => $this->women->id, 'response' => 'yes']);
        app(RoundMatchScore::class)->recalculateApplication($this->application);
        expect((float) $this->application->fresh()->match_score)->toBe(100.0);

        // A new 0-point-earned question would have cost them points before; now it is left out until they answer it
        $this->registered->update(['match_points' => 30]);
        RoundCustomQuestion::create([
            'round_id' => $this->round->id, 'question_text' => 'Exports?', 'question_type' => 'yes_no', 'is_required' => true,
            'display_order' => 3, 'use_in_match_score' => true, 'match_points' => 30, 'match_answers' => ['yes'],
        ]);
        app(RoundMatchScore::class)->recalculateRound($this->round);
        expect((float) $this->application->fresh()->match_score)->toBe(100.0);
    });

    it('shows an unanswered question to the applicant as not answered in the preview', function () {
        $this->actingAsApplicant()->postJson("/api/v1/programs/match-score/{$this->program->id}", [
            'round_answers' => [['question_id' => $this->registered->id, 'response' => 'yes']],
        ])->assertOk()
            ->assertJsonPath('data.match_score', 100)
            ->assertJsonPath('data.breakdown_for_applicant.1.status', 'not answered');
    });
});
