<?php

use App\Models\Communication\Notifications;
use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\ProgramApplication;
use App\Service\AiReview\AiReviewDispatcher;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Programs\AiReview\AiReviewTestCase;

uses(AiReviewTestCase::class);

function ownerNotes($t, string $like) { return Notifications::where('receiver_id', $t->orgUser->id)->where('text', 'like', $like)->count(); }
function pastReviewPeriod($t) { $t->round->update(['close_date' => now()->subDays(5), 'review_period_end' => now()->subDay()]); }

it('tells the owner once when an AI-only round has been fully scored after the review period', function () {
    $this->aiRound('ai_only');
    $this->llm->default = $this->report();
    app(AiReviewDispatcher::class)->dispatchFor($this->application);
    expect(ownerNotes($this, '%scored by AI%'))->toBe(0);          // no per-application notices, and not before the period ends

    Artisan::call('ai-reviews:sweep');
    expect(ownerNotes($this, '%scored by AI%'))->toBe(0);

    pastReviewPeriod($this);
    Artisan::call('ai-reviews:sweep');
    Artisan::call('ai-reviews:sweep');
    expect(ownerNotes($this, '%scored by AI%'))->toBe(1)->and($this->round->fresh()->ai_review_notified_at)->not->toBeNull();
});

it('lists applications that could not be scored and leaves them for manual scoring', function () {
    $this->aiRound('ai_only');
    $this->llm->default = $this->report();
    app(AiReviewDispatcher::class)->dispatchFor($this->application);

    $second = ProgramApplication::factory()->create(['program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $this->round->id, 'round_status' => 'submitted', 'startup_name' => 'Stuck Co']);
    ApplicationAiReview::create(['application_id' => $second->id, 'round_id' => $this->round->id, 'program_id' => $this->program->id, 'mode' => 'ai_only', 'status' => 'failed', 'attempts' => 3, 'input_hash' => app(\App\Service\AiReview\ReviewInputBuilder::class)->quickHash($second, $this->round)]);
    config(['ai_review.external_calls_disabled' => true]); // the sweep must not retry it successfully

    pastReviewPeriod($this);
    Artisan::call('ai-reviews:sweep');
    expect(ownerNotes($this, '%needs attention%'))->toBe(1);
    expect(Notifications::where('receiver_id', $this->orgUser->id)->where('text', 'like', '%Stuck Co%')->exists())->toBeTrue();
    Artisan::call('ai-reviews:sweep');
    expect(ownerNotes($this, '%needs attention%'))->toBe(1);        // exactly once
    expect($second->fresh()->round_status)->toBe('submitted');       // still waiting for the owner to score it
});

it('warns the owner once when the provider capacity cannot cover the waiting applications', function () {
    $this->aiRound('ai_assisted');
    config(['ai_review.external_calls_disabled' => true, 'ai_review.providers.groq.limits' => ['rpm' => 5, 'tpm' => 100000, 'rpd' => 2, 'reset_tz' => 'UTC']]);
    $this->round->update(['close_date' => now()->addDays(1), 'review_period_end' => now()->addDays(5)]);
    ProgramApplication::factory()->count(8)->create(['program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $this->round->id, 'round_status' => 'submitted']);

    Artisan::call('ai-reviews:sweep');
    Artisan::call('ai-reviews:sweep');
    expect(ownerNotes($this, '%capacity%'))->toBe(1)->and($this->round->fresh()->ai_capacity_notified_at)->not->toBeNull();
});

it('picks up submitted applications that never got a review and retries failed ones within the limit', function () {
    $this->aiRound('ai_assisted');
    $this->llm->default = $this->report();
    Artisan::call('ai-reviews:sweep');
    expect(ApplicationAiReview::where('application_id', $this->application->id)->where('status', 'ready')->exists())->toBeTrue();

    $r = ApplicationAiReview::first();
    $r->update(['status' => 'failed', 'attempts' => 1]);
    Artisan::call('ai-reviews:sweep');
    expect($r->fresh()->status)->toBe('ready');
});

it('does nothing when AI review is switched off', function () {
    $this->aiRound('ai_assisted');
    config(['ai_review.enabled' => false]);
    Artisan::call('ai-reviews:sweep');
    expect(ApplicationAiReview::count())->toBe(0);
});
