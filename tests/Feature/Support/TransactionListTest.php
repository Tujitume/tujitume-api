<?php

use App\Models\Auth\User;
use App\Models\Finance\Transactions;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

function tx(array $o): Transactions
{
    return Transactions::create($o + ['type' => 'deposit', 'method' => 'lipr', 'gross_amount' => 100, 'fee_amount' => 0, 'net_amount' => 100, 'status' => 'settled', 'direction' => 'credit']);
}

beforeEach(function () {
    $this->me = User::factory()->create(['first_name' => 'Mina', 'last_name' => 'Me', 'user_type_id' => 1]);
    $this->other = User::factory()->create(['first_name' => 'Otto', 'last_name' => 'Other', 'user_type_id' => 1]);
    $this->stranger = User::factory()->create(['user_type_id' => 1]);

    tx(['user_id' => $this->me->id, 'type' => 'deposit', 'direction' => 'credit', 'net_amount' => 500, 'gross_amount' => 520, 'fee_amount' => 20, 'reference_id' => 'REF-A']);
    tx(['user_id' => $this->me->id, 'type' => 'withdraw', 'direction' => 'debit', 'net_amount' => 200, 'gross_amount' => 200, 'method' => 'bank_transfer', 'reference_id' => 'REF-B']);
    // someone else paid me: stored as THEIR debit, it is money in for me
    tx(['user_id' => $this->other->id, 'recipient_id' => $this->me->id, 'type' => 'program_milestone', 'direction' => 'debit', 'net_amount' => 300, 'gross_amount' => 300, 'reference_id' => 'REF-C']);
    tx(['user_id' => $this->me->id, 'type' => 'deposit', 'direction' => 'credit', 'status' => 'failed', 'net_amount' => 900, 'gross_amount' => 900, 'reference_id' => 'REF-D']);
    tx(['user_id' => $this->me->id, 'type' => 'deposit', 'direction' => 'credit', 'status' => 'pending', 'net_amount' => 50, 'gross_amount' => 50, 'reference_id' => 'REF-E']);
    tx(['user_id' => $this->stranger->id, 'type' => 'deposit', 'direction' => 'credit', 'reference_id' => 'REF-X']); // not mine
});

function call($test, array $q)
{
    return $test->actingAs($test->me, 'sanctum')->getJson('/api/v1/transactions?'.http_build_query($q));
}

it('keeps the old full list when no page, search or filter is asked for', function () {
    $r = $this->actingAs($this->me, 'sanctum')->getJson('/api/v1/transactions')->assertOk();
    expect($r->json('data'))->toHaveCount(5)->and($r->json('data.0'))->not->toHaveKey('items');
});

it('pages only my transactions and says how many there are', function () {
    $r = call($this, ['page' => 1, 'per_page' => 2])->assertOk();
    expect($r->json('data.items'))->toHaveCount(2)
        ->and($r->json('data.pagination.total'))->toBe(5)
        ->and($r->json('data.pagination.last_page'))->toBe(3);
});

it('shows a payment I received as money in', function () {
    $items = call($this, ['page' => 1, 'per_page' => 50, 'search' => 'REF-C'])->json('data.items');
    expect($items)->toHaveCount(1)->and($items[0]['direction'])->toBe('credit');
});

it('searches words in any order across reference, type, method and names', function () {
    expect(call($this, ['page' => 1, 'search' => 'ref-a'])->json('data.items'))->toHaveCount(1);
    expect(call($this, ['page' => 1, 'search' => 'program milestone'])->json('data.items'))->toHaveCount(1);
    expect(call($this, ['page' => 1, 'search' => 'otto'])->json('data.items'))->toHaveCount(1);          // the sender's name
    expect(call($this, ['page' => 1, 'search' => 'bank transfer'])->json('data.items'))->toHaveCount(1); // method
    expect(call($this, ['page' => 1, 'search' => 'REF-X'])->json('data.items'))->toHaveCount(0);        // not my transaction
});

it('filters by direction using the account point of view', function () {
    expect(call($this, ['page' => 1, 'filters' => ['direction' => ['credit']]])->json('data.pagination.total'))->toBe(4);
    expect(call($this, ['page' => 1, 'filters' => ['direction' => ['debit']]])->json('data.pagination.total'))->toBe(1);
    expect(call($this, ['page' => 1, 'filters' => ['direction' => ['debit', 'credit']]])->json('data.pagination.total'))->toBe(5);
});

it('filters by status, type, method and date together', function () {
    $today = now()->toDateString();
    expect(call($this, ['page' => 1, 'filters' => ['status' => ['failed']]])->json('data.pagination.total'))->toBe(1);
    expect(call($this, ['page' => 1, 'filters' => ['type' => ['deposit'], 'status' => ['settled']]])->json('data.pagination.total'))->toBe(1);
    expect(call($this, ['page' => 1, 'filters' => ['method' => ['bank_transfer']]])->json('data.pagination.total'))->toBe(1);
    expect(call($this, ['page' => 1, 'filters' => ['date' => "$today~$today"]])->json('data.pagination.total'))->toBe(5);
    expect(call($this, ['page' => 1, 'filters' => ['date' => '2001-01-01~2001-01-31']])->json('data.pagination.total'))->toBe(0);
});

it('sorts on the database and ignores unknown sorts', function () {
    $amounts = collect(call($this, ['page' => 1, 'sort' => '-amount'])->json('data.items'))->pluck('net_amount')->map(fn ($v) => (float) $v)->all();
    expect($amounts)->toEqual([900.0, 500.0, 300.0, 200.0, 50.0]);
    call($this, ['page' => 1, 'sort' => 'password'])->assertOk();
});

it('exports every match, not a page', function () {
    $r = call($this, ['export' => 1, 'per_page' => 2, 'filters' => ['direction' => ['credit']]]);
    expect($r->json('data.items'))->toHaveCount(4)->and($r->json('data.pagination'))->toBeNull();
});

it('counts filter options with the other filters applied', function () {
    $facets = call($this, ['page' => 1, 'filters' => ['status' => ['failed']]])->json('data.facets');
    expect(collect($facets['status'])->pluck('count', 'value')->all())->toEqual(['settled' => 3, 'failed' => 1, 'pending' => 1])
        ->and(collect($facets['direction'])->pluck('count', 'value')->all())->toBe(['credit' => 1]);
});

it('totals the summary cards over every transaction, not the search', function () {
    $stats = call($this, ['page' => 1, 'search' => 'nothing-matches-this'])->json('data.stats.by_currency.KES');
    expect($stats['in_count'])->toBe(3)                 // deposit 500 + received 300 + pending 50 (failed is not counted)
        ->and($stats['in_total'])->toEqual(850.0)
        ->and($stats['in_largest'])->toEqual(500.0)
        ->and($stats['out_count'])->toBe(1)->and($stats['out_total'])->toEqual(200.0)
        ->and($stats['fees'])->toEqual(20.0)
        ->and($stats['open_count'])->toBe(1)->and($stats['open_total'])->toEqual(50.0)
        ->and($stats['failed_count'])->toBe(1)->and($stats['refunded_count'])->toBe(0);
});

it('says which currency each row is in and keeps the totals per currency', function () {
    tx(['user_id' => $this->me->id, 'type' => 'deposit', 'method' => 'stripe', 'direction' => 'credit', 'net_amount' => 25, 'gross_amount' => 25, 'reference_id' => 'REF-USD']);

    $items = collect(call($this, ['page' => 1, 'per_page' => 50])->json('data.items'));
    expect($items->firstWhere('reference_id', 'REF-USD')['currency'])->toBe('USD')
        ->and($items->firstWhere('reference_id', 'REF-A')['currency'])->toBe('KES');

    $stats = call($this, ['page' => 1])->json('data.stats.by_currency');
    expect($stats['USD']['in_total'])->toEqual(25)->and($stats['USD']['in_count'])->toBe(1)
        ->and($stats['KES']['in_total'])->toEqual(850); // the USD deposit is not added into the KES total
});

it('tells the page which account it is looking at', function () {
    expect(call($this, ['page' => 1])->json('data.account_id'))->toBe($this->me->id);
});
