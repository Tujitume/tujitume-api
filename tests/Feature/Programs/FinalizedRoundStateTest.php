<?php

use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\ReviewerOrder;
use App\Service\Program\RoundApplicantList;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// ISSUE-39: a finalized round asks nobody for action, so a review that never completed is "AI review didn't run".
// ISSUE-40: the round knows which Business Coach fees are ready to pay, already sent, or waiting for approval.

it('shows reviews that never completed in a finalized round as not run', function () {
    $this->round->update(['review_method' => 'ai_assisted', 'status' => 'finalized']);
    ApplicationAiReview::create(['application_id' => $this->application->id, 'round_id' => $this->round->id, 'program_id' => $this->program->id, 'mode' => 'ai_assisted', 'status' => 'needs_attention', 'input_hash' => 'test-hash']);

    [$rows] = RoundApplicantList::build($this->round->fresh());
    expect($rows->firstWhere('id', $this->application->id)['ai_review']['status'])->toBe('not_run')
        ->and(RoundApplicantList::aiKey('not_run'))->toBe('not_run');

    $this->round->update(['status' => 'published']);
    [$rows] = RoundApplicantList::build($this->round->fresh());
    expect($rows->firstWhere('id', $this->application->id)['ai_review']['status'])->toBe('needs_attention');
});

it('counts only fees that are ready to send as payable', function () {
    $this->round->update(['status' => 'finalized']);
    $order = fn (string $work, string $pay) => ReviewerOrder::create(['organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id,
        'program_id' => $this->program->id, 'round_id' => $this->round->id, 'order_type' => 'round_review', 'fee_amount' => 3000,
        'acceptance_status' => 'accepted', 'work_status' => $work, 'payment_status' => $pay]);
    $order('approved', 'pending'); $order('approved', 'pending'); // both already sent

    [, $stats] = RoundApplicantList::build($this->round->fresh());
    expect($stats['reviewer_payments_to_start'])->toBe(0)->and($stats['reviewer_pay'])->toMatchArray(['to_pay_count' => 0, 'sent_count' => 2]);

    $order('approved', 'unpaid'); $order('delivered', 'unpaid');
    [, $stats] = RoundApplicantList::build($this->round->fresh());
    expect($stats['reviewer_pay'])->toMatchArray(['to_pay_count' => 1, 'to_pay_total' => 3000.0, 'awaiting_approval_count' => 1]);
});
