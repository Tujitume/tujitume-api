<?php

use App\Models\Auth\User;
use App\Models\EmailVerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['user_type_id' => 1, 'email' => 'owner@example.com', 'password' => bcrypt('old-password')]);
});

function emailCode(string $email, string $code = '1234'): void
{
    EmailVerificationCode::create(['email' => $email, 'code' => Hash::make($code), 'expires_at' => now()->addMinutes(10)]);
}

it('refuses to set a password from the email address alone', function () {
    $this->postJson('/api/v1/resetPassword', ['email' => 'owner@example.com', 'password' => 'new-password-1'])
        ->assertStatus(422);
    $this->postJson('/api/v1/resetPassword', ['email' => 'owner@example.com', 'password' => 'new-password-1', 'reset_token' => str_repeat('a', 64)])
        ->assertForbidden();

    expect(Hash::check('old-password', $this->user->fresh()->password))->toBeTrue();
});

it('sets the password with the token from the emailed code, once', function () {
    emailCode('owner@example.com');
    $token = $this->postJson('/api/v1/verifyEmailOtp', ['email' => 'owner@example.com', 'code' => '1234'])
        ->assertOk()->json('reset_token');

    $this->postJson('/api/v1/resetPassword', ['email' => 'Owner@Example.com', 'password' => 'new-password-1', 'reset_token' => $token])
        ->assertOk();
    expect(Hash::check('new-password-1', $this->user->fresh()->password))->toBeTrue();

    // Used up
    $this->postJson('/api/v1/resetPassword', ['email' => 'owner@example.com', 'password' => 'again-password', 'reset_token' => $token])
        ->assertForbidden();
});

it('a token for one address cannot reset another account', function () {
    $victim = User::factory()->create(['user_type_id' => 1, 'email' => 'victim@example.com', 'password' => bcrypt('victim-password')]);
    emailCode('attacker@example.com');
    $token = $this->postJson('/api/v1/verifyEmailOtp', ['email' => 'attacker@example.com', 'code' => '1234'])->json('reset_token');

    $this->postJson('/api/v1/resetPassword', ['email' => 'victim@example.com', 'password' => 'new-password-1', 'reset_token' => $token])
        ->assertForbidden();
    expect(Hash::check('victim-password', $victim->fresh()->password))->toBeTrue();
});

it('a wrong code gives no token', function () {
    emailCode('owner@example.com');
    $this->postJson('/api/v1/verifyEmailOtp', ['email' => 'owner@example.com', 'code' => '9999'])
        ->assertStatus(422)->assertJsonMissingPath('reset_token');
});
