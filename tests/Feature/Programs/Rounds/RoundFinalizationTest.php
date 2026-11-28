<?php

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Round finalization', function () {
    it('requires authentication', function () {
        assertProgramUnauthenticated('POST', "rounds/{$this->round->id}/finalize");
    });
    it('rejects a non-owner', function () {
        $this->actingAsApplicant()->postJson("/api/v1/programs/rounds/{$this->round->id}/finalize")->assertStatus(403)->assertJson(['success' => false]);
    });
});

describe('Round finalization emails', function () {
    it('sends each applicant one email: winners of the last round the award, those left out not selected', function () {
        config(['mail.default' => 'array']);
        $winner = $this->application;
        $winner->update(['status' => 'awarded', 'awarded_amount' => 50000]);
        $leftUser = \App\Models\Auth\User::factory()->create(['user_type_id' => 1, 'email' => 'left.out@example.com']);
        $leftOut = \App\Models\Programs\ProgramApplication::factory()->create(['program_id' => $this->program->id, 'user_id' => $leftUser->id, 'current_round_id' => $this->round->id]);

        // Past the winner limit, the left-out applicant is on both lists the finalize step builds
        (new \App\Service\Program\RoundFinalizationService())->sendFinalizationNotifications(
            $this->round->fresh(), [$winner->fresh(), $leftOut->fresh()], [$leftOut->fresh()], null, true, 1
        );

        $to = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => [
            'to' => $m->getOriginalMessage()->getTo()[0]->getAddress(), 'subject' => $m->getOriginalMessage()->getSubject(),
        ]);
        expect($to->where('to', 'left.out@example.com')->count())->toBe(1)
            ->and($to->where('to', $this->applicantUser->email)->count())->toBe(1)
            ->and($to->pluck('subject')->implode(' | '))->not->toContain('Advanced to the Next Round');
    });
});

describe('Round opened email', function () {
    it('tells the applicants waiting in round 2 once, at its opening moment', function () {
        config(['mail.default' => 'array']);
        $round2 = \App\Models\Programs\Rounds\ProgramRound::factory()->create([
            'program_id' => $this->program->id, 'round_number' => 2, 'round_name' => 'Pitch day', 'status' => 'published',
        ]);
        $round2->setWindowMoment('opens', now()->subMinutes(10))->setWindowMoment('closes', now()->addWeek())->save();
        $this->application->update(['current_round_id' => $round2->id, 'round_status' => 'draft']);

        $this->artisan('update:program-status')->assertSuccessful();
        $this->artisan('update:program-status')->assertSuccessful(); // the next run sends nothing more

        $sent = collect(app('mailer')->getSymfonyTransport()->messages())
            ->filter(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress() === $this->applicantUser->email);
        expect($sent)->toHaveCount(1)->and($round2->fresh()->opened_notified_at)->not->toBeNull();
    });
});

describe('Round finalize preview', function () {
    it('lists an applicant who failed a knockout once in not selected', function () {
        $this->round->update(['advancement_mode' => 'score_threshold', 'score_threshold' => 60, 'close_date' => now()->subDays(3), 'review_period_end' => now()->subDays(2)]);
        $this->program->update(['total_rounds' => 1]);
        $this->application->update(['knockout_status' => 'failed', 'round_status' => 'submitted']);

        $ids = collect($this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/finalize-preview")
            ->assertOk()->json('data.not_selected'))->pluck('id');

        expect($ids->filter(fn ($id) => $id === $this->application->id)->count())->toBe(1);
    });
});

describe('Round finalization preview', function () {
    it('says whether each Business Coach on the pay list is a team member or an invited coach', function () {
        $assignment = \App\Models\Programs\Rounds\RoundReviewer::create(['round_id' => $this->round->id, 'user_id' => $this->reviewerUser->id, 'reviewer_type' => 'external', 'acceptance_status' => 'accepted']);
        \App\Models\ReviewerOrder::create(['organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'round_reviewer_id' => $assignment->id,
            'program_id' => $this->program->id, 'round_id' => $this->round->id, 'order_type' => 'round_review', 'fee_amount' => 0,
            'acceptance_status' => 'accepted', 'work_status' => 'pending', 'payment_status' => 'unpaid']);

        $row = $this->actingAs($this->orgUser, 'sanctum')->getJson("/api/v1/programs/rounds/{$this->round->id}/finalize-preview")
            ->assertOk()->json('data.reviewer_pay.reviewers.0');
        expect($row['reviewer_type'])->toBe('external');
    });
});
