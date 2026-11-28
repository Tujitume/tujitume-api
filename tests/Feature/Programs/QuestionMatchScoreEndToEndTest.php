<?php

use App\Models\Business\Listing;
use App\Models\Programs\ProgramApplication;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// "Use in match score" per Round 1 question, end to end: the owner scores questions in Round Configuration, the
// applicant sees which questions count (but not the points or answer key), previews the score, and the application
// is scored and held to the program's minimum.
it('scores applications from the round questions the owner marks, out of 100', function () {
    $this->program->update(['use_match_score' => true, 'match_score_limit' => 50, 'application_deadline' => now()->addMonth()]);
    $this->round->update(['open_date' => now()->subDay(), 'close_date' => now()->addMonth()]);
    $base = "/api/v1/programs/rounds/{$this->round->id}/questions";

    // A scored question must be required, have points, and all of them together cannot pass 100
    $this->actingAsOrgOwner()->postJson($base, [
        'question_text' => 'Registered?', 'question_type' => 'yes_no', 'is_required' => false, 'display_order' => 1,
        'use_in_match_score' => true, 'match_points' => 60, 'match_answers' => ['yes'],
    ])->assertStatus(422)->assertJsonValidationErrors(['is_required']);
    $registered = $this->actingAsOrgOwner()->postJson($base, [
        'question_text' => 'Registered?', 'question_type' => 'yes_no', 'is_required' => true, 'display_order' => 1,
        'use_in_match_score' => true, 'match_points' => 60, 'match_answers' => ['yes'],
    ])->assertCreated()->json('data.id');
    $this->actingAsOrgOwner()->postJson($base, [
        'question_text' => 'Too many points', 'question_type' => 'yes_no', 'is_required' => true, 'display_order' => 2,
        'use_in_match_score' => true, 'match_points' => 50, 'match_answers' => ['yes'],
    ])->assertStatus(422)->assertJsonValidationErrors(['match_points']);
    $sector = $this->actingAsOrgOwner()->postJson($base, [
        'question_text' => 'Sector', 'question_type' => 'dropdown', 'is_required' => true, 'display_order' => 2,
        'options' => ['Agriculture', 'Retail'], 'use_in_match_score' => true, 'match_points' => 40,
        'match_answers' => ['Agriculture' => 40, 'Retail' => 10],
    ])->assertCreated()->json('data.id');

    // The applicant sees which questions count, never their points or answer key
    $info = $this->actingAsApplicant()->getJson("/api/v1/programs/{$this->program->id}/sme/applications")->assertOk();
    $questions = collect($info->json('application_round.questions') ?? $info->json('data.application_round.questions'));
    expect($questions->firstWhere('id', $sector)['use_in_match_score'])->toBeTrue()
        ->and($questions->firstWhere('id', $sector))->not->toHaveKeys(['match_points', 'match_answers']);

    // Live preview while applying
    $answers = [['question_id' => $registered, 'response' => 'yes'], ['question_id' => $sector, 'response' => 'Retail']];
    $this->actingAsApplicant()->postJson("/api/v1/programs/match-score/{$this->program->id}", ['round_answers' => $answers])
        ->assertOk()->assertJsonPath('data.match_score', 70)->assertJsonPath('data.below_threshold', false);

    // Someone who has not applied yet (the shared applicant already has an application here)
    $newcomer = \App\Models\Auth\User::factory()->create(['user_type_id' => 1]);
    $this->createVerifiedKyc($newcomer, 'entrepreneur');
    $business = Listing::create(['user_id' => $newcomer->id, 'name' => 'Northstar Foods']);
    $apply = fn (array $roundAnswers) => $this->actingAs($newcomer, 'sanctum')->postJson("/api/v1/programs/{$this->program->id}/applications", [
        'business_id' => $business->id, 'startup_name' => 'Northstar Foods', 'contact_person_name' => 'Amina Otieno',
        'contact_person_email' => 'amina@example.test', 'sector' => 'Food', 'headquarters_location' => 'Nairobi',
        'total_amount_requested' => 1000, 'round_answers' => $roundAnswers,
    ]);

    // Below the minimum (10 of 100): refused, nothing kept
    $before = ProgramApplication::count();
    $apply([['question_id' => $registered, 'response' => 'no'], ['question_id' => $sector, 'response' => 'Retail']])
        ->assertStatus(422)->assertJsonValidationErrors(['match_score']);
    expect(ProgramApplication::count())->toBe($before);

    // 60 + 10 = 70: accepted and stored with its breakdown
    $id = $apply($answers)->assertCreated()->json('data.id');
    $stored = ProgramApplication::find($id);
    expect((float) $stored->match_score)->toBe(70.0)
        ->and(collect($stored->score_breakdown)->pluck('earned_points')->all())->toBe([60, 10]);
});
