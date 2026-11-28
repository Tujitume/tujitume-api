<?php

use App\Models\Auth\User;
use App\Models\Finance\LiprPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

/*
 * An M-Pesa wallet deposit can only be credited by the person who started it (lipr_deposit records them), and never
 * with a payment made for something else (a capital release, a bid...), which would credit the money twice.
 */
function depositRow(string $ref, int $userId, string $purpose = 'deposit', string $status = 'successful'): LiprPayment
{
    return LiprPayment::create(['reference_id' => $ref, 'transaction_id' => 'T-'.$ref, 'user_id' => $userId, 'status' => $status,
        'purpose' => $purpose, 'amount' => 600, 'amount_usd' => 4.6]);
}

beforeEach(function () {
    $this->owner = User::factory()->create(['user_type_id' => 5]);
    $this->other = User::factory()->create(['user_type_id' => 1]);
    $this->status = fn (User $u, string $ref) => $this->actingAs($u, 'sanctum')->postJson('/api/v1/lipr/deposit-status', ['referenceId' => $ref]);
});

it('credits a deposit only to the person who started it', function () {
    depositRow('DEP-OWN', $this->owner->id);

    ($this->status)($this->other, 'DEP-OWN')->assertForbidden();
    expect(LiprPayment::where('reference_id', 'DEP-OWN')->value('status'))->toBe('successful');

    ($this->status)($this->owner, 'DEP-OWN')->assertOk()->assertJsonPath('status', 'completed');
    expect(LiprPayment::where('reference_id', 'DEP-OWN')->value('status'))->toBe('completed');
});

it('refuses to credit a payment that was made for something else', function () {
    depositRow('CAP-REL', $this->owner->id, 'capital_milestone');

    ($this->status)($this->owner, 'CAP-REL')->assertStatus(422);
    expect(LiprPayment::where('reference_id', 'CAP-REL')->value('status'))->toBe('successful');
});

it('still credits an older deposit row that has no owner recorded', function () {
    depositRow('DEP-OLD', 0, 'transfer');

    ($this->status)($this->owner, 'DEP-OLD')->assertOk()->assertJsonPath('status', 'completed');
});
