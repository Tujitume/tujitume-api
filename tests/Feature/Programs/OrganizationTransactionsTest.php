<?php

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Finance\Transactions;
use App\Service\Program\OrganizationWithdrawal;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// Owner, 28 Nov: the organization wallet lists the organization's money, My Wallet a person's own, My Transactions both.

function orgTx(array $o): Transactions
{
    return Transactions::create($o + ['type' => 'deposit', 'method' => 'lipr', 'gross_amount' => 100, 'fee_amount' => 0, 'net_amount' => 100, 'status' => 'settled', 'direction' => 'credit']);
}

beforeEach(function () {
    $this->member = User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
    OrganizationUserRole::create(['organization_id' => $this->organization->id, 'user_id' => $this->member->id,
        'role_id' => Role::firstOrCreate(['name' => 'viewer'], ['access_types' => []])->id, 'status' => 'active', 'accepted_at' => now()]);

    // Organization money (set by Transactions::organizationOf)
    $this->orgDeposit = orgTx(['user_id' => $this->orgUser->id, 'method' => 'org-wallet', 'reference_id' => 'ORG-DEP']);
    $this->orgPayout = orgTx(['user_id' => $this->orgUser->id, 'recipient_id' => $this->applicantUser->id, 'type' => 'program_milestone', 'direction' => 'debit', 'reference_id' => 'ORG-PAY']);
    $this->orgOut = orgTx(['user_id' => $this->orgUser->id, 'type' => 'withdraw', 'direction' => 'debit', 'method' => OrganizationWithdrawal::METHOD, 'reference_id' => 'OW-1']);
    // Personal money
    $this->ownerIn = orgTx(['user_id' => $this->orgUser->id, 'type' => 'deposit', 'direction' => 'credit', 'method' => OrganizationWithdrawal::METHOD, 'reference_id' => 'OW-1']);
    $this->ownerOwn = orgTx(['user_id' => $this->orgUser->id, 'reference_id' => 'OWNER-OWN']);
    $this->memberOwn = orgTx(['user_id' => $this->member->id, 'reference_id' => 'MEMBER-OWN']);
});

function refs($test, User $as, string $scope): array
{
    return collect($test->actingAs($as, 'sanctum')->getJson('/api/v1/transactions?'.http_build_query(['page' => 1, 'per_page' => 50, 'scope' => $scope]))
        ->assertOk()->json('data.items'))->pluck('reference_id')->sort()->values()->all();
}

it('marks organization wallet money with the organization', function () {
    foreach ([$this->orgDeposit, $this->orgPayout, $this->orgOut] as $t) expect($t->fresh()->organization_id)->toBe($this->organization->id);
    foreach ([$this->ownerIn, $this->ownerOwn, $this->memberOwn] as $t) expect($t->fresh()->organization_id)->toBeNull();
});

it('lists organization, personal and all transactions', function () {
    expect(refs($this, $this->orgUser, 'organization'))->toBe(['ORG-DEP', 'ORG-PAY', 'OW-1'])
        ->and(refs($this, $this->orgUser, 'personal'))->toBe(['OW-1', 'OWNER-OWN'])
        ->and(refs($this, $this->orgUser, 'all'))->toBe(['ORG-DEP', 'ORG-PAY', 'OW-1', 'OW-1', 'OWNER-OWN']);
});

it('shows a team member the organization\'s money and their own, never the owner\'s personal money', function () {
    expect(refs($this, $this->member, 'organization'))->toBe(['ORG-DEP', 'ORG-PAY', 'OW-1'])
        ->and(refs($this, $this->member, 'personal'))->toBe(['MEMBER-OWN'])
        ->and(refs($this, $this->member, 'all'))->toBe(['MEMBER-OWN', 'ORG-DEP', 'ORG-PAY', 'OW-1']);
});

it('counts the organization withdrawal as money out of the organization and into My Wallet', function () {
    $items = collect($this->actingAs($this->orgUser, 'sanctum')->getJson('/api/v1/transactions?page=1&per_page=50&scope=all&search=OW-1')->json('data.items'));
    expect($items->pluck('direction')->sort()->values()->all())->toBe(['credit', 'debit']);
    $stats = $this->actingAs($this->orgUser, 'sanctum')->getJson('/api/v1/transactions?page=1&per_page=50&scope=organization')->json('data.stats.by_currency.KES');
    expect($stats['in_count'])->toBe(1)->and($stats['out_count'])->toBe(2);
});

it('gives someone outside an organization only their own money', function () {
    expect(refs($this, $this->applicantUser, 'organization'))->toBe([])
        ->and(refs($this, $this->applicantUser, 'all'))->toBe(['ORG-PAY']);
});

it('shows a program payout once in the entrepreneur\'s My Wallet when both sides have a row', function () {
    // Stored once per side with the same reference (ProgramDisbursementController / LIPR callback)
    orgTx(['user_id' => $this->orgUser->id, 'recipient_id' => $this->applicantUser->id, 'type' => 'program_milestone', 'direction' => 'debit', 'reference_id' => 'PAY-2']);
    orgTx(['user_id' => $this->applicantUser->id, 'recipient_id' => $this->applicantUser->id, 'type' => 'program_milestone', 'direction' => 'credit', 'reference_id' => 'PAY-2']);

    foreach (['personal', 'all'] as $scope) {
        expect(refs($this, $this->applicantUser, $scope))->toBe(['ORG-PAY', 'PAY-2']);
    }
    $credit = collect($this->actingAs($this->applicantUser, 'sanctum')->getJson('/api/v1/transactions?page=1&per_page=50&search=PAY-2')->json('data.items'));
    expect($credit)->toHaveCount(1)->and($credit->first()['direction'])->toBe('credit');
});
