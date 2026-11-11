<?php

use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->existing = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Wanjiru', 'dob' => '22-03-1995', 'email' => 'jane.wanjiru@gmail.com', 'user_type_id' => 1]);
});

function check(array $body) { return test()->postJson('/api/v1/register/check-duplicate', $body); }

it('finds an account with the same name and date of birth and shows only a small hint', function () {
    $res = check(['first_name' => 'Jane', 'last_name' => 'Wanjiru', 'dob' => '22-03-1995'])->assertOk();
    expect($res->json('match'))->toBeTrue()
        ->and($res->json('account.name'))->toBe('Jane W.')
        ->and($res->json('account.email_hint'))->toBe('ja***u@gmail.com')
        ->and($res->json('account.same_email'))->toBeFalse();
    $body = json_encode($res->json());
    expect($body)->not->toContain('jane.wanjiru')->not->toContain('1995')->not->toContain('Wanjiru');
});

it('ignores case, spacing and the date format', function () {
    expect(check(['first_name' => '  jane ', 'last_name' => 'WANJIRU', 'dob' => '1995-03-22'])->json('match'))->toBeTrue();
    expect(check(['first_name' => 'Jane', 'last_name' => 'Wanjiru', 'dob' => '22/3/1995'])->json('match'))->toBeTrue();
    $stored = User::factory()->create(['first_name' => 'Peter', 'last_name' => 'Kamau', 'dob' => '1990-01-05', 'email' => 'p@x.com']);
    expect(check(['first_name' => 'Peter', 'last_name' => 'Kamau', 'dob' => '05-01-1990'])->json('match'))->toBeTrue();
});

it('does not match a different birthday, a different name or a missing date of birth', function () {
    expect(check(['first_name' => 'Jane', 'last_name' => 'Wanjiru', 'dob' => '23-03-1995'])->json('match'))->toBeFalse();
    expect(check(['first_name' => 'Jane', 'last_name' => 'Otieno', 'dob' => '22-03-1995'])->json('match'))->toBeFalse();
    expect(check(['first_name' => 'Jane', 'last_name' => 'Wanjiru', 'dob' => 'not a date'])->json('match'))->toBeFalse();
    check(['first_name' => 'Jane', 'last_name' => 'Wanjiru'])->assertStatus(422);
});

it('says when the typed email is the very same one', function () {
    $res = check(['first_name' => 'Jane', 'last_name' => 'Wanjiru', 'dob' => '22-03-1995', 'email' => 'JANE.WANJIRU@gmail.com']);
    expect($res->json('account.same_email'))->toBeTrue();
});

it('masks short emails without giving them away', function () {
    $this->existing->update(['email' => 'jo@mail.co']);
    expect(check(['first_name' => 'Jane', 'last_name' => 'Wanjiru', 'dob' => '22-03-1995'])->json('account.email_hint'))->toBe('j***@mail.co');
});

it('is throttled so it cannot be used to browse accounts', function () {
    foreach (range(1, 12) as $_) check(['first_name' => 'A', 'last_name' => 'B', 'dob' => '01-01-2000'])->assertOk();
    check(['first_name' => 'A', 'last_name' => 'B', 'dob' => '01-01-2000'])->assertStatus(429);
});
