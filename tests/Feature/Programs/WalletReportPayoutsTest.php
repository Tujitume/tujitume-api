<?php

use App\Models\Programs\Disbursement;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\Rounds\ProgramRound;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// ISSUE-31 (e2e test 2026-11-27): payouts to the applicant were missing from the wallet report, and the fees the program
// paid on top were shown nowhere.
it('lists payouts to the applicant and the fees in the wallet report', function () {
    $ms = ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => 1, 'is_template' => false, 'amount' => 100000]);
    Disbursement::create(['milestone_id' => $ms->id, 'wallet_id' => $this->wallet->id, 'recipient_type' => 'business_owner', 'amount' => 100000,
        'fee_amount' => 6500, 'payment_method' => 'mpesa_mobile', 'currency' => 'KES', 'status' => 'completed', 'authorized_by' => $this->orgUser->id]);

    $res = $this->actingAs($this->orgUser, 'sanctum')->getJson("/api/v1/programs/{$this->program->id}/overview/wallet")->assertOk();
    expect(collect($res->json('data.items'))->firstWhere('kind', 'business'))->toMatchArray(['amount' => 100000, 'fee' => 6500])
        ->and((float) $res->json('data.analytics.fees_total'))->toBe(6500.0)
        ->and((float) $res->json('data.analytics.paid_with_fees_total'))->toBe(106500.0);
});

it('serves one round on GET programs/rounds/{round} (no missing show method)', function () {
    $this->actingAs($this->orgUser, 'sanctum')->getJson("/api/v1/programs/rounds/{$this->round->id}")->assertOk();
});
