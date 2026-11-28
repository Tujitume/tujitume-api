<?php

use App\Models\Kyc\KycVerification;
use App\Models\ReviewerOrder;
use App\Service\Program\OrganizationWalletLipr;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// DECISION-6 (owner, 27 Nov): the organization has its own wallet (own LIPR account, KES balance). Moving money to a program
// sets it aside (it can no longer be withdrawn), unused program money can be moved back, and only Super Admins act.

beforeEach(function () {
    $this->organization->forceFill(['lipr_wallet' => 'ORG-W', 'wallet_balance' => 100000])->save();
    $this->wallet->forceFill(['balance' => 0, 'total_deposited' => 0])->save();
});

it('shows the organization wallet: free, set aside and total', function () {
    $data = $this->actingAs($this->orgUser, 'sanctum')->getJson('/api/v1/programs/organization-wallet')->assertOk()->json('data');
    expect($data)->toMatchArray(['lipr_wallet' => 'ORG-W', 'available' => 100000, 'set_aside' => 0, 'total' => 100000, 'can_manage' => true]);
});

it('runs the owner\'s scenario: move 50,000, then withdraw at most 50,000', function () {
    $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/wallets/{$this->wallet->id}/transfer", ['amount' => 50000])->assertOk();
    expect((float) $this->organization->fresh()->wallet_balance)->toBe(50000.0)->and((float) $this->wallet->fresh()->balance)->toBe(50000.0);

    // Withdrawals go to the Super Admin's own My Wallet after an emailed code (OrganizationWithdrawalTest)
    $this->orgUser->forceFill(['lipr_wallet' => 'ME-W'])->save();
    $w2w = Mockery::mock(\App\Service\LiprMpesa\LiprW2W::class);
    $w2w->shouldReceive('send')->andReturn(['success' => true, 'reference' => 'W1']);
    app()->instance(\App\Service\LiprMpesa\LiprW2W::class, $w2w);

    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw/code', ['amount' => 100000])
        ->assertStatus(422);
    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw/code', ['amount' => 50000])->assertOk();
    \Illuminate\Support\Facades\Cache::put('org-withdraw:'.$this->organization->id.':'.$this->orgUser->id,
        ['hash' => hash_hmac('sha256', '123456', (string) config('app.key')), 'amount' => 50000.0, 'attempts' => 0], now()->addMinutes(10));
    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw', ['amount' => 50000, 'code' => '123456'])
        ->assertOk();
    expect((float) $this->organization->fresh()->wallet_balance)->toBe(0.0);
});

it('moves only uncommitted program money back to the organization wallet', function () {
    $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/wallets/{$this->wallet->id}/transfer", ['amount' => 10000])->assertOk();
    ReviewerOrder::create(['organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'program_id' => $this->program->id,
        'order_type' => 'round_review', 'fee_amount' => 3000, 'work_status' => 'approved', 'payment_status' => 'unpaid']);

    $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/wallets/{$this->wallet->id}/move-back", ['amount' => 8000])
        ->assertStatus(422)->assertJsonFragment(['message' => 'Only KES 7,000.00 of this program wallet is free; the rest is promised to approved payments (coach and site visit fees, approved releases).']);
    $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/wallets/{$this->wallet->id}/move-back", ['amount' => 7000])->assertOk();
    expect((float) $this->wallet->fresh()->balance)->toBe(3000.0)->and((float) $this->organization->fresh()->wallet_balance)->toBe(97000.0);
});

it('lets only Super Admins move money, and refuses a move larger than the free balance', function () {
    $viewer = \App\Models\Auth\User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
    $this->actingAs($viewer, 'sanctum')->postJson("/api/v1/programs/wallets/{$this->wallet->id}/transfer", ['amount' => 100])->assertForbidden();
    $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/wallets/{$this->wallet->id}/transfer", ['amount' => 200000])->assertStatus(422);
});

it('sets up the organization wallet only once its KYC is verified', function () {
    $this->organization->forceFill(['lipr_wallet' => null])->save();
    KycVerification::where('organization_id', $this->organization->id)->update(['status' => 'submitted']);
    $stub = Mockery::mock(OrganizationWalletLipr::class);
    $stub->shouldReceive('createAccount')->andReturn(['success' => true, 'account' => 'ORG-NEW']);
    app()->instance(OrganizationWalletLipr::class, $stub);

    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/setup')->assertStatus(422);
    KycVerification::where('organization_id', $this->organization->id)->update(['status' => 'verified']);
    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/setup')->assertOk();
    expect($this->organization->fresh()->lipr_wallet)->toBe('ORG-NEW');
});

it('tells the page the deposit status it polls for, and credits a confirmed deposit once', function () {
    \App\Models\Finance\LiprPayment::create(['reference_id' => 'DEP-1', 'transaction_id' => 'T-1', 'user_id' => 0, 'status' => 'Successful', 'purpose' => 'transfer', 'amount' => 5000, 'amount_usd' => 38]);
    $check = fn () => $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/deposit-status', ['reference' => 'DEP-1'])->assertOk();

    expect($check()->json('status'))->toBe('completed')->and((float) $this->organization->fresh()->wallet_balance)->toBe(105000.0);
    expect($check()->json('status'))->toBe('completed')->and((float) $this->organization->fresh()->wallet_balance)->toBe(105000.0);
    expect($this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/deposit-status', ['reference' => 'NONE'])->json('status'))->toBe('pending');
});
