<?php

use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;
use App\Service\AiReview\AiReviewDispatcher;
use Tests\Feature\Programs\AiReview\AiReviewTestCase;

uses(AiReviewTestCase::class);

function secondRound($t): ProgramRound
{
    $r2 = ProgramRound::factory()->create(['program_id' => $t->program->id, 'round_number' => 2, 'round_name' => 'Round two', 'status' => 'published', 'open_date' => now()->subDay(), 'close_date' => now()->addMonth(), 'advancement_mode' => 'manual']);
    $t->program->update(['program_type' => 'multi_round', 'total_rounds' => 2]);
    \App\Models\ApplicationRoundHistory::create(['application_id' => $t->application->id, 'round_id' => $t->round->id, 'round_number' => 1, 'entered_at' => now()->subDays(3), 'submitted_at' => now()->subDays(3), 'outcome' => 'in_progress']);

    return $r2;
}

it('AI-assisted, full chain: submit -> AI report -> human score -> advance', function () {
    $this->aiRound('ai_assisted');
    $r2 = secondRound($this);
    $this->llm->default = $this->report();

    // submit -> report (no score rows, nothing moved)
    app(AiReviewDispatcher::class)->dispatchFor($this->application);
    expect(ApplicationAiReview::first()->status)->toBe('ready')->and(ApplicationScore::count())->toBe(0);

    // the reviewer reads the suggestion, then scores on their own through the existing endpoint
    $this->actingAsOrgOwner()->postJson("/api/v1/programs/applications/{$this->application->id}/scores", ['criterion_scores' => [['criterion' => 'Impact', 'score' => 9], ['criterion' => 'Viability', 'score' => 8]]])->assertStatus(201);
    expect(ApplicationScore::pluck('source')->all())->toBe(['human'])->and((float) $this->application->fresh()->average_score)->toBe(86.0);

    // the owner advances (a human decision)
    $this->actingAsOrgOwner()->postJson("/api/v1/programs/applications/{$this->application->id}/advance")->assertOk();
    expect($this->application->fresh()->current_round_id)->toBe($r2->id);
});

it('AI-only, full chain: submit -> AI score -> sweep notice -> owner decision', function () {
    $this->aiRound('ai_only');
    $r2 = secondRound($this);
    $this->llm->default = $this->report();

    app(AiReviewDispatcher::class)->dispatchFor($this->application);
    $score = ApplicationScore::first();
    expect($score->source)->toBe('ai')->and($this->application->fresh()->round_status)->toBe('scored');

    $this->round->update(['close_date' => now()->subDays(4), 'review_period_end' => now()->subDay()]);
    \Illuminate\Support\Facades\Artisan::call('ai-reviews:sweep');
    expect(\App\Models\Communication\Notifications::where('receiver_id', $this->orgUser->id)->where('text', 'like', '%scored by AI%')->count())->toBe(1);

    // AI never advanced anyone: the owner does
    expect($this->application->fresh()->current_round_id)->toBe($this->round->id);
    $this->actingAsOrgOwner()->postJson("/api/v1/programs/applications/{$this->application->id}/advance")->assertOk();
    expect($this->application->fresh()->current_round_id)->toBe($r2->id);
});

it('manual rounds behave exactly as before: no AI rows, no jobs, human scores only', function () {
    \Illuminate\Support\Facades\Bus::fake();
    expect(app(AiReviewDispatcher::class)->dispatchFor($this->application))->toBeNull();
    $this->actingAsOrgOwner()->postJson("/api/v1/programs/applications/{$this->application->id}/scores", ['criterion_scores' => [['criterion' => 'Impact', 'score' => 5]]])->assertStatus(201);
    expect(ApplicationAiReview::count())->toBe(0)->and(ApplicationScore::first()->source)->toBe('human')->and((float) $this->application->fresh()->average_score)->toBe(50.0);
    \Illuminate\Support\Facades\Bus::assertNothingDispatched();
});
