<?php

use App\Models\Programs\Ai\ApplicationAiFinding;
use App\Service\AiReview\AiReviewDispatcher;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Programs\AiReview\AiReviewTestCase;

uses(AiReviewTestCase::class);

beforeEach(function () {
    $this->aiRound('ai_assisted');
    $this->assignReviewer();
    $this->llm->default = $this->report();
    app(AiReviewDispatcher::class)->dispatchFor($this->application);
    $this->base = "/api/v1/programs/applications/{$this->application->id}/ai-review";
});

it('shows the owner the report with explicit AI provenance and source links', function () {
    $res = $this->actingAsOrgOwner()->getJson($this->base)->assertOk();
    $d = $res->json('data');
    expect($d['origin'])->toBe('ai')->and($d['status'])->toBe('ready')->and($d['suggested_scores'][0]['origin'])->toBe('ai')->and($d['quality']['verified_quote_ratio'])->toEqual(1);
    $f = $d['findings'][0];
    expect($f['origin'])->toBe('ai')->and($f['quote_verified'])->toBeTrue()->and($f['source']['type'])->toBe('answer')->and($f['source']['question_id'])->toBe($this->question->id);
    expect(json_encode($d))->not->toContain('text_content')->not->toContain('file_path');
});

it('returns reviewer-friendly criterion explanations with their linked question and answer', function () {
    $this->round->update(['scoring_criteria' => [
        ['name' => 'Impact', 'description' => 'Assess the business answer. Based on: Q1 "Describe your business".', 'weight' => 60, 'score_range' => '0-10'],
        ['name' => 'Viability', 'description' => 'Assess viability. Based on: Q1 "Describe your business".', 'weight' => 40, 'score_range' => '0-10'],
    ]]);

    $data = $this->actingAsOrgOwner()->getJson($this->base)->assertOk()->json('data');
    $impact = collect($data['criteria_reviews'])->firstWhere('criterion', 'Impact');

    expect((float) $impact['score'])->toBe(8.0)
        ->and((float) $impact['max_score'])->toBe(10.0)
        ->and((float) $impact['weight_percent'])->toBe(60.0)
        ->and((float) $impact['weighted_points'])->toBe(48.0)
        ->and($impact['rationale'])->toBe('Serves 200 farmers')
        ->and($impact['question']['id'])->toBe($this->question->id)
        ->and($impact['question']['text'])->toBe('Describe your business')
        ->and($impact['question']['answer'])->toBe(self::ANSWER)
        ->and($data['questions_and_answers'][0]['answered'])->toBeTrue();
});

it('lets an assigned reviewer read it but not an unassigned one, another organization or an applicant', function () {
    $this->actingAsReviewer()->getJson($this->base)->assertOk();
    \App\Models\Programs\Rounds\ReviewerApplicationAssignment::query()->delete();
    $this->actingAsReviewer()->getJson($this->base)->assertStatus(403);
    $this->actingAs($this->otherOrgUser(), 'sanctum')->getJson($this->base)->assertStatus(403);
    $this->actingAsApplicant()->getJson($this->base)->assertStatus(403);
});

it('returns 404 for a manual round', function () {
    $this->round->update(['review_method' => 'manual']);
    $this->actingAsOrgOwner()->getJson($this->base)->assertStatus(404);
});

it('lets a reviewer add a human finding that is labelled human', function () {
    $res = $this->actingAsReviewer()->postJson("{$this->base}/findings", ['dimension' => 'traction', 'polarity' => 'risk', 'title' => 'Site visit needed', 'body' => 'I could not verify the customers.'])->assertCreated();
    expect($res->json('data.origin'))->toBe('human')->and($res->json('data.author_user_id'))->toBe($this->reviewerUser->id);
    $findings = $this->actingAsOrgOwner()->getJson($this->base)->json('data.findings');
    expect(collect($findings)->pluck('origin')->all())->toBe(['ai', 'human']);
});

it('confirms, dismisses and overrides a finding while preserving the original AI text', function () {
    $f = ApplicationAiFinding::where('author_type', 'ai')->first();
    $url = "/api/v1/programs/ai-findings/{$f->id}";

    $this->actingAsReviewer()->patchJson($url, ['action' => 'confirm'])->assertOk()->assertJsonPath('data.status', 'confirmed');
    $this->actingAsReviewer()->patchJson($url, ['action' => 'dismiss'])->assertOk()->assertJsonPath('data.status', 'dismissed');
    $this->actingAsReviewer()->patchJson($url, ['action' => 'override', 'body' => 'New wording'])->assertStatus(422);
    $res = $this->actingAsReviewer()->patchJson($url, ['action' => 'override', 'body' => 'Revenue grew 40% (verified)', 'reviewer_note' => 'Checked the accounts'])->assertOk();
    expect($res->json('data.status'))->toBe('overridden')->and($res->json('data.body'))->toBe('Revenue grew 40% (verified)')->and($res->json('data.original_body'))->toBe('Revenue grew by 40%.')
        ->and($res->json('data.reviewed_by'))->toBe($this->reviewerUser->id);
    $res2 = $this->actingAsReviewer()->patchJson($url, ['action' => 'override', 'body' => 'Third wording', 'reviewer_note' => 'again'])->assertOk();
    expect($res2->json('data.original_body'))->toBe('Revenue grew by 40%.');
    expect(DB::table('ai_review_audit_logs')->where('action', 'finding_reviewed')->count())->toBe(4);
});

it('forbids changing findings for strangers and after the round is finalized', function () {
    $f = ApplicationAiFinding::first();
    $this->actingAs($this->otherOrgUser(), 'sanctum')->patchJson("/api/v1/programs/ai-findings/{$f->id}", ['action' => 'confirm'])->assertStatus(403);
    $this->actingAsApplicant()->patchJson("/api/v1/programs/ai-findings/{$f->id}", ['action' => 'confirm'])->assertStatus(403);
    $this->round->update(['status' => 'finalized']);
    $this->actingAsOrgOwner()->patchJson("/api/v1/programs/ai-findings/{$f->id}", ['action' => 'confirm'])->assertStatus(422);
});

it('gives the owner a round overview with capacity, provider and status counts', function () {
    $res = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews")->assertOk();
    $d = $res->json('data');
    expect($d['review_method'])->toBe('ai_assisted')->and($d['counts']['ready'])->toBe(1)->and($d['provider'])->toBe('groq')->and($d['privacy_gate_satisfied'])->toBeTrue()
        ->and($d['capacity']['requests_remaining_today'])->toBeGreaterThan(0)->and($d['applications'][0]['status'])->toBe('ready');
    $this->actingAsReviewer()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews")->assertStatus(403);
});

it('shows only the latest AI review attempt for each application in round activity', function () {
    $old = \App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $this->application->id)->firstOrFail();
    $latest = $old->replicate();
    $latest->input_hash = hash('sha256', $old->input_hash.'latest-attempt');
    $latest->status = 'needs_attention';
    $latest->error = 'Latest attempt needs attention.';
    $latest->completed_at = now();
    $latest->save();

    $data = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->assertOk()->json('data');

    expect($data['recent'])->toHaveCount(1)
        ->and($data['recent'][0]['status'])->toBe('needs_attention')
        ->and($data['recent'][0]['error'])->toBe('Latest attempt needs attention.')
        ->and($data['counts']['attention'])->toBe(1)
        ->and($data['counts']['done'])->toBe(0);
});

it('lets only the owner retry a review the AI could not finish, and audits it', function () {
    $this->actingAsReviewer()->postJson("{$this->base}/rerun")->assertStatus(403);
    // A finished review is never run again: it would spend tokens, and the reviewer can edit the scores by hand
    $this->actingAsOrgOwner()->postJson("{$this->base}/rerun")->assertStatus(422);
    \App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $this->application->id)->update(['status' => 'needs_attention']);
    $this->actingAsOrgOwner()->postJson("{$this->base}/rerun")->assertStatus(202);
    expect(DB::table('ai_review_audit_logs')->where('action', 'rerun')->exists())->toBeTrue();
    $this->round->update(['review_method' => 'manual']);
    $this->actingAsOrgOwner()->postJson("{$this->base}/rerun")->assertStatus(422);
});

it('never leaks AI data in any applicant-facing response', function () {
    foreach (["/api/v1/programs/applications/{$this->application->id}", '/api/v1/programs/my-applications', "/api/v1/programs/applications/{$this->application->id}/rounds", "/api/v1/programs/{$this->program->id}/sme/applications"] as $url) {
        $body = json_encode($this->actingAsApplicant()->getJson($url)->json());
        foreach (['Revenue grew', 'suggested_scores', 'deterministic_checks', 'application_ai', 'Prefer rural impact', 'quote_verified', 'ai_review_config'] as $secret) {
            expect($body)->not->toContain($secret);
        }
    }
});

it('tells the owner what the AI is doing for the round: counts, the finished timeline and an estimate, in a small answer', function () {
    $res = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->assertOk();
    $d = $res->json('data');

    expect($d['counts']['total'])->toBe(1)->and($d['counts']['done'])->toBe(1)->and($d['counts']['waiting'])->toBe(0)
        ->and($d['state'])->toBe('done')
        ->and($d['now'])->toBeNull()->and($d['queue'])->toBe([])
        ->and($d['recent'])->toHaveCount(1)->and($d['recent'][0]['application_id'])->toBe($this->application->id)
        ->and($d['recent'][0]['status'])->toBe('ready')->and($d['recent'][0]['completed_at'])->not->toBeNull()
        ->and(json_encode($d))->not->toContain('text_content');
});

it('lists waiting reviews with the latest first and counts the ones not shown', function () {
    $review = \App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $this->application->id)->first();
    $review->update(['status' => 'queued', 'completed_at' => null, 'started_at' => null]);

    $d = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->assertOk()->json('data');

    expect($d['state'])->toBeIn(['starting', 'paused'])->and($d['counts']['waiting'])->toBe(1)
        ->and($d['queue'])->toHaveCount(1)->and($d['queue'][0]['queued_at'])->not->toBeNull()->and($d['queue_more'])->toBe(0);
});

it('keeps the activity of a round away from people who do not own the program', function () {
    $this->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->assertStatus(401);
});

it('carries on with waiting reviews without re-scoring a finished one', function () {
    $review = \App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $this->application->id)->first();
    $before = $review->completed_at;

    $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/resume")->assertStatus(202)->assertJsonPath('data.started', 0);
    expect($review->fresh()->status)->toBe('ready')->and($review->fresh()->completed_at->equalTo($before))->toBeTrue();

    $review->update(['status' => 'queued']);
    $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/resume")->assertStatus(202)->assertJsonPath('data.started', 1);
});

it('does not let a stranger resume the AI of a round', function () {
    $this->postJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/resume")->assertStatus(401);
});

it('never sends the test-data generator\'s applications to the AI', function () {
    \App\Service\Misc\AutomatedAccounts::$reviewWithAi = false;
    $user = \App\Models\Auth\User::factory()->create(['email' => 'testuser7@example123.com', 'user_type_id' => 1]);
    $app = \App\Models\Programs\ProgramApplication::factory()->create(['program_id' => $this->program->id, 'user_id' => $user->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $this->round->id, 'status' => 'pending', 'round_status' => 'submitted']);

    expect(app(AiReviewDispatcher::class)->dispatchFor($app))->toBeNull()
        ->and(\App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $app->id)->exists())->toBeFalse();

    $d = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->assertOk()->json('data');
    expect($d['counts']['total'])->toBe(1); // the real application only
});

it('sends no email to the test-data generator\'s accounts, but does to real ones', function () {
    config(['mail.default' => 'array']);
    \Illuminate\Support\Facades\Mail::purge('array');
    \Illuminate\Support\Facades\Mail::raw('Hello', fn ($m) => $m->to('testuser12@example456.com')->subject('Test'));
    \Illuminate\Support\Facades\Mail::raw('Hello', fn ($m) => $m->to('owen@gmail.com')->subject('Real'));

    $sent = collect(\Illuminate\Support\Facades\Mail::mailer('array')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getEnvelope()->getRecipients()[0]->getAddress());
    expect($sent->all())->toBe(['owen@gmail.com']);
});

it('reviews the generator\'s applications only while the temporary switch is on', function () {
    \App\Service\Misc\AutomatedAccounts::$reviewWithAi = false;
    $user = \App\Models\Auth\User::factory()->create(['email' => 'testuser9@example321.com', 'user_type_id' => 1]);
    $app = \App\Models\Programs\ProgramApplication::factory()->create(['program_id' => $this->program->id, 'user_id' => $user->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $this->round->id, 'status' => 'pending', 'round_status' => 'submitted']);

    expect(app(AiReviewDispatcher::class)->dispatchFor($app))->toBeNull();

    \App\Service\Misc\AutomatedAccounts::$reviewWithAi = true;
    expect(app(AiReviewDispatcher::class)->dispatchFor($app))->not->toBeNull();
    $d = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->assertOk()->json('data');
    expect($d['counts']['total'])->toBe(2);
});

afterEach(fn () => \App\Service\Misc\AutomatedAccounts::$reviewWithAi = true);

it('lets the owner stop the AI of a round, holds new work back, and continues it later', function () {
    $this->actingAsReviewer()->postJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/stop")->assertStatus(403);
    $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/stop")->assertOk();

    $d = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->json('data');
    expect($d['state'])->toBe('stopped')->and($d['stopped'])->toBeTrue()->and($d['stalled'])->toBeFalse();

    // A queued review is not started while stopped, but the row is kept
    $review = \App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $this->application->id)->first();
    $review->update(['status' => 'queued']);
    \App\Jobs\RunApplicationAiReviewJob::dispatchSync($review->id);
    expect($review->fresh()->status)->toBe('queued');

    // Continue lifts the stop
    $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/resume")->assertStatus(202);
    expect($this->round->fresh()->ai_review_paused_at)->toBeNull();
});

it('works through every waiting review by itself with one command, and stops when the owner stops it', function () {
    $review = \App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $this->application->id)->first();
    $review->update(['status' => 'queued', 'completed_at' => null]);

    // Stopped: nothing is started
    $this->round->forceFill(['ai_review_paused_at' => now()])->save();
    \Illuminate\Support\Facades\Artisan::call('ai-reviews:work', ['round' => $this->round->id]);
    expect($review->fresh()->status)->toBe('queued');

    // Continued: it finishes the waiting one with no further asking
    $this->round->forceFill(['ai_review_paused_at' => null])->save();
    \Illuminate\Support\Facades\Artisan::call('ai-reviews:work', ['round' => $this->round->id]);
    expect($review->fresh()->status)->toBe('ready');
});

it('gives the finished and the needs-attention reviews as separate lists for the tabs', function () {
    $review = \App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $this->application->id)->first();
    $d = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->json('data');
    expect($d['done_list'])->toHaveCount(1)->and($d['attention_list'])->toBe([]);

    $review->update(['status' => 'needs_attention', 'error' => 'x']);
    $d = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->json('data');
    expect($d['done_list'])->toBe([])->and($d['attention_list'])->toHaveCount(1)->and($d['attention_list'][0]['status'])->toBe('needs_attention');
});

it('pages the AI tab tables: pending, success and needs attention, newest first', function () {
    $url = fn (string $tab, string $extra = '') => "/api/v1/programs/rounds/{$this->round->id}/ai-reviews/items?tab={$tab}{$extra}";

    $success = $this->actingAsOrgOwner()->getJson($url('success'))->assertOk()->json('data');
    expect($success['items'])->toHaveCount(1)->and($success['items'][0]['status'])->toBe('ready')->and($success['meta']['total'])->toBe(1)
        ->and($this->actingAsOrgOwner()->getJson($url('pending'))->json('data.meta.total'))->toBe(0)
        ->and($this->actingAsOrgOwner()->getJson($url('attention'))->json('data.meta.total'))->toBe(0);

    \App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $this->application->id)->update(['status' => 'queued', 'completed_at' => null]);
    $pending = $this->actingAsOrgOwner()->getJson($url('pending', '&per_page=10&page=1'))->json('data');
    expect($pending['items'])->toHaveCount(1)->and($pending['items'][0]['status'])->toBe('queued')->and($pending['items'][0]['at'])->not->toBeNull();

    $this->actingAsOrgOwner()->getJson($url('pending', '&per_page=7'))->assertStatus(422);
});

it('keeps the AI tab tables away from people who are not signed in', function () {
    $this->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/items?tab=pending")->assertStatus(401);
});

it('keeps two rounds of two programs apart: stopping or working one never touches the other', function () {
    $program2 = \App\Models\Programs\Program::factory()->create(['user_id' => $this->orgUser->id, 'status' => 'published']);
    $round5 = \App\Models\Programs\Rounds\ProgramRound::factory()->create(['program_id' => $program2->id, 'round_number' => 1, 'status' => 'published', 'advancement_mode' => 'manual', 'review_method' => 'ai_assisted', 'scoring_criteria' => $this->round->scoring_criteria]);
    $applicant = \App\Models\Auth\User::factory()->create(['user_type_id' => 1]);
    $app2 = \App\Models\Programs\ProgramApplication::factory()->create(['program_id' => $program2->id, 'user_id' => $applicant->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $round5->id, 'status' => 'pending', 'round_status' => 'submitted']);
    $review2 = \App\Models\Programs\Ai\ApplicationAiReview::create(['application_id' => $app2->id, 'round_id' => $round5->id, 'program_id' => $program2->id, 'mode' => 'ai_assisted', 'status' => 'queued', 'input_hash' => 'x']);

    // Stop the first round only
    $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/stop")->assertOk();
    $a = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->json('data');
    $b = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$round5->id}/ai-reviews/activity")->json('data');
    expect($a['state'])->toBe('stopped')->and($b['state'])->not->toBe('stopped')
        ->and($a['counts']['total'])->toBe(1)->and($b['counts']['total'])->toBe(1)->and($b['counts']['waiting'])->toBe(1);

    // Working the other round touches only its own review
    \Illuminate\Support\Facades\Artisan::call('ai-reviews:work', ['round' => $round5->id]);
    expect($review2->fresh()->status)->not->toBe('queued')
        ->and(\App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $this->application->id)->first()->round_id)->toBe($this->round->id);
});

it('runs again, in one go, only the reviews the AI could not finish', function () {
    $review = \App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $this->application->id)->first();
    $url = "/api/v1/programs/rounds/{$this->round->id}/ai-reviews/rerun-attention";

    // A finished review is never run again
    $this->actingAsOrgOwner()->postJson($url)->assertStatus(202)->assertJsonPath('data.queued', 0);
    expect($review->fresh()->status)->toBe('ready');

    $review->update(['status' => 'needs_attention', 'error' => 'x']);
    $this->actingAsReviewer()->postJson($url)->assertStatus(403);
    $this->actingAsOrgOwner()->postJson($url, ['application_ids' => [999999]])->assertStatus(202)->assertJsonPath('data.queued', 0);
    expect($review->fresh()->status)->toBe('needs_attention');

    $this->actingAsOrgOwner()->postJson($url, ['application_ids' => [$this->application->id]])->assertStatus(202)->assertJsonPath('data.queued', 1);
    expect($review->fresh()->status)->not->toBe('needs_attention');
});

it('says how many seconds are left as a number, from the waiting count and the pace', function () {
    $review = \App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $this->application->id)->first();
    $review->update(['status' => 'queued', 'completed_at' => null]);
    $d = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->assertOk()->json('data');

    expect($d['counts']['waiting'])->toBe(1);
    if ($d['seconds_per_application'] !== null && ! $d['stalled']) {
        expect($d['estimated_seconds_left'])->toBeInt()->toBeGreaterThanOrEqual($d['seconds_per_application']);
    }
});

it('counts an application that has moved on to a Business Coach (assigned, under review), not only submitted and scored ones', function () {
    $total = fn () => $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->json('data.counts.total');
    expect($total())->toBe(1);

    foreach (['assigned', 'under_review'] as $i => $status) {
        $u = \App\Models\Auth\User::factory()->create(['user_type_id' => 1]);
        \App\Models\Programs\ProgramApplication::factory()->create(['program_id' => $this->program->id, 'user_id' => $u->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $this->round->id, 'status' => 'pending', 'round_status' => $status]);
    }
    // a draft (not submitted yet) is not an application to review
    $d = \App\Models\Auth\User::factory()->create(['user_type_id' => 1]);
    \App\Models\Programs\ProgramApplication::factory()->create(['program_id' => $this->program->id, 'user_id' => $d->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $this->round->id, 'status' => 'pending', 'round_status' => 'draft']);

    expect($total())->toBe(3);
});

it('puts a newly submitted application in the queue and shows it under Pending', function () {
    $u = \App\Models\Auth\User::factory()->create(['user_type_id' => 1]);
    $new = \App\Models\Programs\ProgramApplication::factory()->create(['program_id' => $this->program->id, 'user_id' => $u->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $this->round->id, 'status' => 'pending', 'round_status' => 'submitted']);

    // Before anything is created for it, it is already listed as waiting
    $this->round->forceFill(['ai_review_paused_at' => now()])->save(); // held, so the review is queued but not run
    app(AiReviewDispatcher::class)->dispatchFor($new);
    $pending = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/items?tab=pending")->json('data');
    expect(collect($pending['items'])->pluck('application_id')->all())->toContain($new->id)
        ->and(\App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $new->id)->value('status'))->toBe('queued');
});

it('lists the applications of the round that the AI does not count, with the reason, so the totals can be reconciled', function () {
    foreach (['draft', 'advanced'] as $status) {
        $u = \App\Models\Auth\User::factory()->create(['user_type_id' => 1]);
        \App\Models\Programs\ProgramApplication::factory()->create(['program_id' => $this->program->id, 'user_id' => $u->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $this->round->id, 'status' => 'pending', 'round_status' => $status]);
    }
    $d = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/ai-reviews/activity")->assertOk()->json('data');

    $byStatus = collect($d['not_counted'])->pluck('count', 'status');
    expect($d['counts']['total'])->toBe(1)->and($byStatus['draft'])->toBe(1)->and($byStatus['advanced'])->toBe(1);
});

it('lets a finished review made by an older version of the AI check be run again once, and nothing else', function () {
    $review = \App\Models\Programs\Ai\ApplicationAiReview::where('application_id', $this->application->id)->first();

    $current = $this->actingAsOrgOwner()->getJson($this->base)->json('data');
    expect($current['outdated'])->toBeFalse();
    $this->actingAsOrgOwner()->postJson("{$this->base}/rerun")->assertStatus(422);

    $review->update(['prompt_version' => 'v0']);
    expect($this->actingAsOrgOwner()->getJson($this->base)->json('data.outdated'))->toBeTrue();
    $this->actingAsOrgOwner()->postJson("{$this->base}/rerun")->assertStatus(202);
});

it('puts where the AI is with each application into the round\'s applicant list, so the table can show it', function () {
    $d = $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/applications")->assertOk()->json();
    $rows = collect($d['applications'] ?? $d['data'] ?? []);
    $row = $rows->firstWhere('id', $this->application->id);

    expect($row['ai_review']['status'])->toBe('ready')->and($row['ai_review'])->toHaveKey('suggested_total');
});
