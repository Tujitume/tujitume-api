<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The deal room's investor list: one entry per investor with their total money and equity. An investment that spilled
 * into the next milestone is two accepted rows; it used to list the same person twice and count them as two investors.
 */
class DealRoomParticipantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_investor_split_over_two_milestones_is_listed_once(): void
    {
        $owner = User::factory()->create(['user_type_id' => 1]);
        $one = User::factory()->create(['user_type_id' => 2]);
        $two = User::factory()->create(['user_type_id' => 2]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Solar lanterns', 'category' => 'Energy', 'location' => 'Nairobi', 'investment_needed' => 400000, 'share' => 20, 'active' => 1]);
        $ms = [];
        foreach (['A1', 'A2'] as $title) {
            $ms[] = DB::table('milestones')->insertGetId(['user_id' => $owner->id, 'listing_id' => $listing->id, 'title' => $title, 'amount' => 200000, 'n_o_days' => 30,
                'funding_duration' => 30, 'status' => 'fully_funded', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[$one, $ms[0], 200000, 10], [$one, $ms[1], 100000, 5], [$two, $ms[1], 100000, 5]] as $i => [$investor, $msId, $amount, $share]) {
            DB::table('accepted_bids')->insert(['bid_id' => $i + 1, 'ms_id' => $msId, 'investor_id' => $investor->id, 'business_id' => $listing->id, 'owner_id' => $owner->id, 'type' => 'Monetary',
                'method' => 'lipr', 'amount' => $amount, 'representation' => $share, 'status' => 'confirmed', 'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        }

        $investors = collect($this->actingAs($owner)->getJson("/api/v1/deal-room/business/{$listing->id}/participants")->assertOk()->json('investors'));

        $this->assertCount(2, $investors);
        $first = $investors->firstWhere('id', $one->id);
        $this->assertEquals(300000, $first['amount']);
        $this->assertEquals(15, (float) $first['percentage']);
        $this->assertEquals(100000, $investors->firstWhere('id', $two->id)['amount']);
    }
}
