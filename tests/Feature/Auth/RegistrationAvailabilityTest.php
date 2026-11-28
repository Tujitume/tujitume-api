<?php

use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('accepts a made-up test domain outside production and reports whether the email is free', function () {
    $this->getJson('/api/v1/registration/availability/email?value=new.person@tujitume.test')
        ->assertOk()->assertJson(['available' => true]);

    User::factory()->create(['email' => 'taken.person@tujitume.test']);
    $this->getJson('/api/v1/registration/availability/email?value=Taken.Person@tujitume.test')
        ->assertOk()->assertJson(['available' => false]);
});

it('names the field in the error instead of saying "value"', function () {
    $res = $this->getJson('/api/v1/registration/availability/email?value=not-an-email')->assertStatus(422);
    expect($res->json('errors.value.0'))->toBe('The email field must be a valid email address.');
});
