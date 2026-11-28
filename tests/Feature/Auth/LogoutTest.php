<?php

use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['user_type_id' => 1]);
    $this->phone = $this->user->createToken('phone')->plainTextToken;
    $this->laptop = $this->user->createToken('laptop')->plainTextToken;
});

it('log out ends this device only, and its token stops working', function () {
    $this->withHeader('Authorization', "Bearer {$this->laptop}")->getJson('/api/v1/logout')->assertNoContent();

    expect(PersonalAccessToken::query()->where('tokenable_id', $this->user->id)->pluck('name')->all())->toBe(['phone']);
});

it('log out of all devices ends every sign-in of the account', function () {
    $other = User::factory()->create(['user_type_id' => 1]);
    $other->createToken('theirs');

    $this->withHeader('Authorization', "Bearer {$this->laptop}")->postJson('/api/v1/logout-all')
        ->assertOk()->assertJsonPath('data.signed_out', 2);

    expect(PersonalAccessToken::query()->where('tokenable_id', $this->user->id)->count())->toBe(0)
        ->and(PersonalAccessToken::query()->where('tokenable_id', $other->id)->count())->toBe(1); // nobody else is touched
});

it('needs a signed-in account to log out everywhere', function () {
    $this->postJson('/api/v1/logout-all')->assertUnauthorized();
});
