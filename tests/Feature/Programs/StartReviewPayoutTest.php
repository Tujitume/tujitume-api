<?php

use App\Models\Auth\User;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use Illuminate\Support\Facades\DB;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// ISSUE-14 (e2e test 2026-11-27): start-review answered 500 for any coach without an M-Pesa wallet. DECISION-2 (owner):
// no coach is asked for payouts to review; a paid coach is reminded at the pay step (ReviewerPaymentService).

function payoutCoach($test, string $type): User
{
    $coach = User::factory()->create(['user_type_id' => 6, 'lipr_wallet' => null, 'stripe_connect_id' => null]);
    DB::table('round_reviewers')->insert(['round_id' => $test->round->id, 'user_id' => $coach->id, 'reviewer_type' => $type,
        'acceptance_status' => 'accepted', 'queue_position' => 1, 'created_at' => now(), 'updated_at' => now()]);
    ReviewerApplicationAssignment::create(['round_id' => $test->round->id, 'reviewer_id' => $coach->id,
        'application_id' => $test->application->id, 'status' => 'assigned', 'assigned_at' => now()]);
    $test->application->update(['round_status' => 'submitted']);

    return $coach;
}

it('lets a paid coach without payouts start a review', function () {
    $coach = payoutCoach($this, 'external');
    $res = $this->actingAs($coach, 'sanctum')->postJson("/api/v1/programs/rounds/{$this->round->id}/applications/{$this->application->id}/start-review");
    expect($res->status())->not->toBe(500)
        ->and((string) ($res->json('error') ?? $res->json('message')))->not->toContain('onboard');
});

it('does not ask an internal coach to set up payouts', function () {
    $coach = payoutCoach($this, 'internal');
    $res = $this->actingAs($coach, 'sanctum')->postJson("/api/v1/programs/rounds/{$this->round->id}/applications/{$this->application->id}/start-review");
    expect($res->status())->not->toBe(500)
        ->and((string) ($res->json('error') ?? $res->json('message')))->not->toContain('onboard');
});

it('reminds a paid coach with no wallet to set up payouts when their payment is due', function () {
    $coach = payoutCoach($this, 'external');
    $order = \App\Models\ReviewerOrder::create([
        'organization_id' => $this->organization->id, 'reviewer_id' => $coach->id, 'program_id' => $this->program->id, 'round_id' => $this->round->id, 'order_type' => 'round_review',
        'round_reviewer_id' => DB::table('round_reviewers')->where('user_id', $coach->id)->value('id'),
        'fee_amount' => 3000, 'work_status' => 'approved', 'payment_status' => 'unpaid', 'minimum_apps_to_get_paid' => 1,
    ]);

    ReviewerApplicationAssignment::where('reviewer_id', $coach->id)->update(['status' => 'completed', 'reviewer_order_id' => $order->id]);

    $result = app(\App\Service\Program\ReviewerPaymentService::class)->pay($order);

    expect($result['ok'])->toBeFalse()->and($result['message'])->toContain('reminded')
        ->and(\App\Models\Communication\Notifications::where('receiver_id', $coach->id)->where('text', 'like', '%set up payouts%')->count())->toBe(1);
});

it('pays coaches from the organization\'s main account, charged to the program wallet (DECISION-4/5)', function () {
    $coach = payoutCoach($this, 'external');
    $coach->forceFill(['lipr_wallet' => 'COACH-W'])->save();
    $this->organization->forceFill(['lipr_wallet' => null])->save();
    $this->wallet->forceFill(['balance' => 10000])->save();
    $order = \App\Models\ReviewerOrder::create([
        'organization_id' => $this->organization->id, 'reviewer_id' => $coach->id, 'program_id' => $this->program->id, 'round_id' => $this->round->id,
        'order_type' => 'round_review', 'round_reviewer_id' => DB::table('round_reviewers')->where('user_id', $coach->id)->value('id'),
        'fee_amount' => 3000, 'work_status' => 'approved', 'payment_status' => 'unpaid', 'minimum_apps_to_get_paid' => 1,
    ]);
    ReviewerApplicationAssignment::where('reviewer_id', $coach->id)->update(['status' => 'completed', 'reviewer_order_id' => $order->id]);

    $result = app(\App\Service\Program\ReviewerPaymentService::class)->pay($order);
    expect($result['ok'])->toBeFalse()->and($result['message'])->toContain('organization wallet is not set up');

    $this->organization->forceFill(['lipr_wallet' => 'ORG-MAIN'])->save();
    $w2w = Mockery::mock(\App\Service\LiprMpesa\LiprW2W::class);
    $w2w->shouldReceive('sendReviewerPayment')->once()->withArgs(fn ($amount, $to, $from) => $from === 'ORG-MAIN' && $to === 'COACH-W')->andReturn(['success' => true]);
    app()->instance(\App\Service\LiprMpesa\LiprW2W::class, $w2w);
    expect(app(\App\Service\Program\ReviewerPaymentService::class)->pay($order->fresh())['ok'])->toBeTrue();
});
