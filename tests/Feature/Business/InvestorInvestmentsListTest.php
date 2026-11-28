<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** My Investments: the investor's own bids and investments, searched, filtered and paged on the server. */
class InvestorInvestmentsListTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_is_the_investors_own_and_pages_and_filters_on_the_server(): void
    {
        $owner = User::factory()->create(['user_type_id' => 1]);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $other = User::factory()->create(['user_type_id' => 2]);
        $kiosks = Listing::create(['user_id' => $owner->id, 'name' => 'Water Kiosks', 'category' => 'Water', 'location' => 'Nairobi', 'investment_needed' => 10000, 'share' => 10]);
        $farm = Listing::create(['user_id' => $owner->id, 'name' => 'Green Farm', 'category' => 'Agri', 'location' => 'Nakuru', 'investment_needed' => 10000, 'share' => 10]);
        $row = fn ($inv, $listing, $amount) => ['investor_id' => $inv->id, 'business_id' => $listing->id, 'owner_id' => $owner->id, 'type' => 'Monetary', 'method' => 'lipr', 'amount' => $amount, 'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()];
        DB::table('business_bids')->insert($row($investor, $kiosks, 1000));
        DB::table('accepted_bids')->insert($row($investor, $farm, 2000) + ['bid_id' => 9, 'status' => 'awaiting_payment']);
        DB::table('accepted_bids')->insert($row($other, $farm, 5000) + ['bid_id' => 10, 'status' => 'confirmed']);

        $res = $this->actingAs($investor)->getJson('/api/v1/milestones/investor/investments/list?page=1&per_page=10')->assertOk();
        $this->assertSame(2, $res->json('data.pagination.total')); // not the other investor's
        $this->assertEqualsCanonicalizing(['pending', 'awaiting_payment'], collect($res->json('data.items'))->pluck('status.value')->all());
        $this->assertSame('warning', collect($res->json('data.items'))->firstWhere('status.value', 'awaiting_payment')['status']['color']);

        $this->actingAs($investor)->getJson('/api/v1/milestones/investor/investments/list?search=kiosk')->assertOk()->assertJsonCount(1, 'data.items');
        $this->actingAs($investor)->getJson('/api/v1/milestones/investor/investments/list?filters[kind][]=investment')->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.business_name', 'Green Farm');
    }
}
