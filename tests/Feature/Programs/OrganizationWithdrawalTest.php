<?php

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Finance\Transactions;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Program\OrganizationWithdrawal;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// Owner, 28 Nov: organization money is withdrawn only by a Super Admin, only to their own My Wallet, and only after
// they enter a code emailed to them.

beforeEach(function () {
    $this->organization->forceFill(['lipr_wallet' => 'ORG-W', 'wallet_balance' => 20000])->save();
    $this->orgUser->forceFill(['lipr_wallet' => 'ME-W', 'email' => 'owner@org.test'])->save();
    $this->sent = [];
    $w2w = Mockery::mock(LiprW2W::class);
    $w2w->shouldReceive('send')->andReturnUsing(function ($amount, $to, $from) {
        $this->sent[] = compact('amount', 'to', 'from');

        return ['success' => $this->w2wOk ?? true, 'reference' => 'OW-REF-'.count($this->sent), 'message' => 'Insufficient funds'];
    });
    app()->instance(LiprW2W::class, $w2w);
});

function emailedCode(): string
{
    $messages = app('mailer')->getSymfonyTransport()->messages()->all();
    $html = (string) end($messages)->getOriginalMessage()->getHtmlBody();
    preg_match('/letter-spacing:0\.4em;">(\d{6})</', $html, $m);

    return $m[1] ?? '';
}

it('emails the super admin a code, then sends the money to their own My Wallet', function () {
    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw/code', ['amount' => 5000])
        ->assertOk()->assertJsonPath('data.email', 'ow•••@org.test');
    $code = emailedCode();
    expect($code)->toHaveLength(6)->and($this->organization->fresh()->wallet_balance)->toEqual(20000);

    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw', ['amount' => 5000, 'code' => $code])
        ->assertOk()->assertJsonPath('message', 'Sent to your My Wallet.')->assertJsonPath('data.available', 15000);

    expect($this->sent)->toBe([['amount' => 5000, 'to' => 'ME-W', 'from' => 'ORG-W']]);
    expect(Transactions::where('method', OrganizationWithdrawal::METHOD)->pluck('type')->sort()->values()->all())->toBe(['deposit', 'withdraw']);

    // The code works once
    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw', ['amount' => 5000, 'code' => $code])
        ->assertStatus(422);
    expect($this->sent)->toHaveCount(1);
});

it('refuses a wrong code, a different amount, and locks after five wrong codes', function () {
    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw/code', ['amount' => 5000])->assertOk();
    $code = emailedCode();
    $wrong = $code === '111111' ? '222222' : '111111';

    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw', ['amount' => 6000, 'code' => $code])
        ->assertStatus(422)->assertJsonPath('message', 'The code was sent for KES 5,000.00. Ask for a new code to withdraw a different amount.');
    foreach (range(1, 4) as $try) {
        $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw', ['amount' => 5000, 'code' => $wrong])
            ->assertStatus(422)->assertJsonPath('message', 'That code is not right. '.(5 - $try).' tries left.');
    }
    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw', ['amount' => 5000, 'code' => $wrong])
        ->assertStatus(422)->assertJsonPath('message', 'Too many wrong codes. Ask for a new code.');
    // Even the right code is dead now
    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw', ['amount' => 5000, 'code' => $code])->assertStatus(422);
    expect($this->sent)->toBeEmpty()->and($this->organization->fresh()->wallet_balance)->toEqual(20000);
});

it('needs the super admin\'s own My Wallet and enough free money', function () {
    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw/code', ['amount' => 25000])->assertStatus(422);
    $this->orgUser->forceFill(['lipr_wallet' => null])->save();
    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw/code', ['amount' => 5000])
        ->assertStatus(422)->assertJsonPath('message', 'Set up your own My Wallet first: organization money is withdrawn to it.');
    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('is for the account owner only, to the owner\'s own My Wallet', function () {
    $editor = User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id, 'lipr_wallet' => 'ED-W']);
    OrganizationUserRole::create(['organization_id' => $this->organization->id, 'user_id' => $editor->id,
        'role_id' => Role::firstOrCreate(['name' => 'editor'], ['access_types' => []])->id, 'status' => 'active', 'accepted_at' => now()]);
    $this->actingAs($editor, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw/code', ['amount' => 5000])->assertStatus(403);

    // Another Super Admin manages the wallet but cannot take money out
    $admin = User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id, 'lipr_wallet' => 'SA-W', 'email' => 'sa@org.test']);
    OrganizationUserRole::create(['organization_id' => $this->organization->id, 'user_id' => $admin->id,
        'role_id' => Role::firstOrCreate(['name' => 'super_admin'], ['access_types' => []])->id, 'status' => 'active', 'accepted_at' => now()]);
    $this->actingAs($admin, 'sanctum')->getJson('/api/v1/programs/organization-wallet')->assertOk()
        ->assertJsonPath('data.can_manage', true)->assertJsonPath('data.can_withdraw', false);
    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw/code', ['amount' => 5000])
        ->assertStatus(403)->assertJsonPath('message', 'Only the account owner can withdraw organization money; it goes to their own My Wallet.');
    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw', ['amount' => 5000, 'code' => '123456'])->assertStatus(403);
    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0)->and($this->sent)->toBeEmpty();

    $this->actingAs($this->orgUser, 'sanctum')->getJson('/api/v1/programs/organization-wallet')->assertJsonPath('data.can_withdraw', true);
});

it('gives the money back when LIPR refuses, at once or in its callback', function () {
    $this->w2wOk = false;
    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw/code', ['amount' => 5000])->assertOk();
    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw', ['amount' => 5000, 'code' => emailedCode()])
        ->assertStatus(422)->assertJsonPath('message', 'The transfer did not go through: Insufficient funds Nothing was taken.');
    expect($this->organization->fresh()->wallet_balance)->toEqual(20000);

    $this->w2wOk = true;
    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw/code', ['amount' => 5000])->assertOk();
    $this->actingAs($this->orgUser, 'sanctum')->postJson('/api/v1/programs/organization-wallet/withdraw', ['amount' => 5000, 'code' => emailedCode()])->assertOk();
    expect($this->organization->fresh()->wallet_balance)->toEqual(15000);

    config(['services.lipr.verify_callbacks' => false]);
    $callback = fn () => $this->postJson('/api/lipr-callback/transfer', [
        'transaction' => ['reference' => 'OW-REF-2', 'id' => 'T2', 'transactionStatus' => 'failed', 'amount' => 5000],
        'metadata' => ['listingId' => 'ow-'.$this->organization->id],
    ])->assertOk();
    $callback();
    $callback();
    expect($this->organization->fresh()->wallet_balance)->toEqual(20000)
        ->and(Transactions::where('reference_id', 'OW-REF-2')->pluck('status')->unique()->all())->toBe(['failed']);
});
