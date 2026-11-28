<?php

use App\Models\Capital\CapitalOffer;

uses(Tests\Feature\Capital\CapitalTestCase::class);

it('keeps the full available amount of a large offer (was cut to 999,999.99)', function () {
    $owner = $this->makeCapitalOwner();

    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/capital/create-capital-offer', [
        'offer_title' => 'Big fund', 'total_capital_available' => 5000000, 'per_startup_allocation' => 2500000,
        'startup_stage' => ['early'], 'sectors' => ['Agriculture'], 'regions' => ['Nairobi'],
    ])->assertOk();

    $offer = CapitalOffer::where('user_id', $owner->id)->latest('id')->first();
    expect((float) $offer->available_amount)->toBe(5000000.0)
        ->and((float) $offer->total_capital_available)->toBe(5000000.0);
});
