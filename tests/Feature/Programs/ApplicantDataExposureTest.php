<?php

use App\Models\Auth\User;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\RoundCustomQuestion;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// e2e test 2026-11-27: ISSUE-8 (anyone could switch an application to hybrid planning) and ISSUE-6 (the applicant saw
// the scoring key of their questions, and their score / eligibility before the round's result).

it('lets only the program team set the planning mode (ISSUE-8)', function () {
    $url = "/api/v1/programs/applications/{$this->application->id}/planning-mode";
    $stranger = User::factory()->create(['user_type_id' => 1]);

    $this->actingAs($stranger, 'sanctum')->postJson($url, ['planning_mode' => 'hybrid'])->assertForbidden();
    $this->actingAs($this->applicantUser, 'sanctum')->postJson($url, ['planning_mode' => 'hybrid'])->assertForbidden();
    expect($this->application->fresh()->planning_mode)->not->toBe('hybrid');

    $this->actingAs($this->orgUser, 'sanctum')->postJson($url, ['planning_mode' => 'hybrid'])->assertOk();
    expect($this->application->fresh()->planning_mode)->toBe('hybrid');
});

it('never shows the applicant the scoring key of their answers (ISSUE-6)', function () {
    $q = RoundCustomQuestion::create([
        'round_id' => $this->round->id, 'question_text' => 'Registered?', 'question_type' => 'knockout_yes_no',
        'knockout_fail_value' => 'no', 'use_in_match_score' => true, 'match_points' => 10, 'match_answers' => ['yes'], 'display_order' => 1,
    ]);
    ApplicationRoundResponse::create(['application_id' => $this->application->id, 'round_id' => $this->round->id, 'question_id' => $q->id, 'response' => 'yes']);
    $url = "/api/v1/programs/applications/{$this->application->id}/rounds/{$this->round->id}/answers";

    $mine = $this->actingAs($this->applicantUser, 'sanctum')->getJson($url)->assertOk()->json('data.0.question');
    expect($mine)->toHaveKey('question_text')->not->toHaveKeys(['match_points', 'match_answers', 'knockout_fail_value']);

    $owner = $this->actingAs($this->orgUser, 'sanctum')->getJson($url)->assertOk()->json('data.0.question');
    expect($owner)->toHaveKeys(['match_points', 'match_answers', 'knockout_fail_value']);
});

it('hides the score and eligibility from the applicant until the result is decided (ISSUE-6)', function () {
    $this->application->update(['round_status' => 'scored', 'average_score' => 85.5, 'is_eligible_to_advance' => true]);
    $url = "/api/v1/programs/applications/{$this->application->id}/rounds";

    $app = $this->actingAs($this->applicantUser, 'sanctum')->getJson($url)->assertOk()->json('application');
    expect($app['round_status'])->toBe('submitted')->and($app['average_score'])->toBeNull()->and($app['is_eligible_to_advance'])->toBeNull();

    $this->application->update(['round_status' => 'advanced']);
    $app = $this->actingAs($this->applicantUser, 'sanctum')->getJson($url)->assertOk()->json('application');
    expect((float) $app['average_score'])->toBe(85.5)->and($app['round_status'])->toBe('advanced');
});
