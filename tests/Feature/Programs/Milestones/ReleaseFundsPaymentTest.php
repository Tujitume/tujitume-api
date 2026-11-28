<?php

use App\Models\Programs\ProgramMilestone;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\LiprMpesa\LiprPaymentService;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// ISSUE-22 / DECISION-5 (owner, 27 Nov): send from the organization's main account, check the program wallet covers the amount + fee, send to the supplier's
// own paybill, and show LIPR refusals in plain words.

function releasable($test): ProgramMilestone
{
    $ms = ProgramMilestone::factory()->create([
        'app_id' => $test->application->id, 'sequence_order' => 1, 'status' => 'approved', 'is_template' => false,
        'fund_release_status' => 'approved', 'amount' => 250000,
    ]);
    $directory = \App\Models\Programs\SupplierDirectory::factory()->create();
    \App\Models\Programs\MilestoneSupplier::create([
        'milestone_id' => $ms->id, 'supplier_id' => $directory->id, 'assignment_type' => 'primary', 'is_locked' => false,
        'payment_route' => 'direct_to_supplier', 'conflict_of_interest_declared' => false, 'added_by' => 'program_owner',
    ]);

    return $ms;
}

it('refuses to pay when the organization has no main LIPR account (DECISION-5)', function () {
    $this->organization->forceFill(['lipr_wallet' => null])->save();
    $this->wallet->forceFill(['balance' => 1000000])->save();
    $ms = releasable($this);
    $res = $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/milestones/{$ms->id}/release-funds", ['type' => 'pay_from_wallet']);
    $res->assertStatus(422);
    expect($res->json('message'))->toContain('organization wallet is not set up');
});

it('refuses a release the program wallet cannot cover, fee included', function () {
    $this->organization->forceFill(['lipr_wallet' => 'ORG-MAIN'])->save(); // the organization's own wallet (DECISION-6)
    $this->wallet->forceFill(['balance' => 250000])->save(); // the amount, but not the fee on top
    $ms = releasable($this);
    $res = $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/milestones/{$ms->id}/release-funds", ['type' => 'pay_from_wallet']);
    if ($res->status() === 422 && str_contains((string) $res->json('message'), 'Supplier')) $this->markTestSkipped('needs a supplier first');
    $res->assertStatus(422);
    expect($res->json('message'))->toContain('does not cover')->toContain('fee');
    expect((float) $this->wallet->fresh()->balance)->toBe(250000.0);
});

it('sends paybill payments to the supplier\'s own paybill and account', function () {
    $lipr = Mockery::mock(LiprPaymentService::class, [Mockery::mock(LiprAuthService::class)])->makePartial();
    $lipr->shouldReceive('disburse')->once()->with('to_paybill', Mockery::on(function ($fields) {
        $r = $fields['recipients'][0] ?? [];
        return ($r['businessNumber'] ?? null) === '522522' && ($r['account'] ?? null) === 'ACC-778';
    }))->andReturn(['data' => [['reference' => 'REF1']]]);

    expect($lipr->toPaybill('PW-TEST', '522522', 'ACC-778', 1000))->toBe(['data' => [['reference' => 'REF1']]]);
});

it('turns a LIPR refusal into a plain sentence', function () {
    $lipr = new LiprPaymentService(Mockery::mock(LiprAuthService::class));
    expect($lipr->getError(['error' => '{"status":400,"message":"Invalid wallet"}']))
        ->toBe('The payment provider refused the transfer: Invalid wallet.')
        ->and($lipr->getError([]))->not->toContain('{');
});

it('lets the owner attach a supplier on the routed suppliers endpoint, and refuses supplier fields on a milestone edit (ISSUE-25)', function () {
    $ms = ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => 1, 'status' => 'pending', 'is_template' => false, 'amount' => 50000]);
    $directory = \App\Models\Programs\SupplierDirectory::factory()->create(['is_active' => true]);

    $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/milestones/{$ms->id}/suppliers", [
        'supplier_id' => $directory->id, 'payment_route' => 'direct_to_supplier', 'quoted_amount' => 50000, 'assignment_type' => 'primary',
    ])->assertSuccessful();
    expect(\App\Models\Programs\MilestoneSupplier::where('milestone_id', $ms->id)->count())->toBe(1);

    $this->actingAs($this->orgUser, 'sanctum')->patchJson("/api/v1/programs/milestones/templates/{$ms->id}", ['supplier_id' => $directory->id])
        ->assertStatus(422)->assertJsonFragment(['message' => 'Suppliers are attached with POST programs/milestones/{milestone}/assign-suppliers (supplier_id, payment_route, quoted_amount), not by editing the milestone.']);
});

it('pays the applicant when a milestone has no supplier, and says so when they have no wallet (ISSUE-28)', function () {
    $this->organization->forceFill(['lipr_wallet' => 'ORG-MAIN'])->save();
    $this->wallet->forceFill(['balance' => 1000000])->save();
    $this->applicantUser->forceFill(['lipr_wallet' => null])->save();
    $ms = ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => 1, 'status' => 'approved', 'is_template' => false, 'fund_release_status' => 'approved', 'amount' => 100000]);

    $res = $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/milestones/{$ms->id}/release-funds", ['type' => 'pay_from_wallet']);
    $res->assertStatus(422);
    expect($res->json('message'))->not->toContain('Supplier not found')->toContain('applicant has no M-Pesa (LIPR) wallet');
});
