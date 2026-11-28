<?php

use App\Models\Auth\User;
use App\Models\EmailVerificationCode;
use App\Service\LiprMpesa\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

// A withdrawal is refused unless the person verified a code the server emailed them in the last 15 minutes;
// each code pays out once. The money provider is never called in these tests.
beforeEach(function () {
    $this->user = User::factory()->create(['user_type_id' => 1, 'email' => 'wanjiru@example.com']);
    $this->paid = 0;
    $this->mock(WithdrawalService::class, function ($mock) {
        $mock->shouldReceive('initiateToMobile')->andReturnUsing(function () {
            $this->paid++;

            return ['success' => true, 'status' => 200, 'data' => ['reference_id' => 'REF-1']];
        });
    });
});

$withdraw = fn () => test()->actingAs(test()->user, 'sanctum')
    ->postJson('/api/v1/lipr/initiate-withdraw', ['amount' => 500, 'phone' => '254700000000']);

it('refuses a withdrawal without a verified emailed code', function () use ($withdraw) {
    $withdraw()->assertStatus(403)->assertJsonPath('code', 'withdraw_code_required');
    expect($this->paid)->toBe(0);
});

it('refuses a code that was sent but never entered, or verified too long ago', function () use ($withdraw) {
    EmailVerificationCode::create(['email' => $this->user->email, 'code' => 'x', 'expires_at' => now()->addMinutes(10)]);
    $withdraw()->assertStatus(403);

    EmailVerificationCode::create(['email' => $this->user->email, 'code' => 'x', 'expires_at' => now(), 'verified_at' => now()->subMinutes(20)]);
    $withdraw()->assertStatus(403);
    expect($this->paid)->toBe(0);
});

it('pays out once per verified code', function () use ($withdraw) {
    EmailVerificationCode::create(['email' => $this->user->email, 'code' => 'x', 'expires_at' => now()->addMinutes(10), 'verified_at' => now()]);

    expect($withdraw()->status())->not->toBe(403);
    expect($this->paid)->toBe(1);

    $withdraw()->assertStatus(403); // the same code cannot be used again
    expect($this->paid)->toBe(1);
});
