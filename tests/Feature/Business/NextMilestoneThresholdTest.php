<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The threshold is the milestone's, not the business's: once milestone 1's money is all accepted, a bid for milestone 2
 * waits until milestone 2's own target is met. threshold_met used to stay 1 for good after milestone 1, so the owner
 * could accept a KSh 100,000 bid on a KSh 500,000 milestone and the deal room kept saying "Target met".
 */
class NextMilestoneThresholdTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_next_milestone_needs_its_own_target_before_bids_are_accepted(): void
    {
        config(['mail.default' => 'array']);
        $owner = User::factory()->create(['user_type_id' => 1]);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $later = User::factory()->create(['user_type_id' => 2]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Solar kiosks', 'category' => 'Energy', 'location' => 'Nakuru',
            'investment_needed' => 900000, 'share' => 10, 'active' => 1, 'threshold_met' => 1]);
        $m1 = DB::table('milestones')->insertGetId(['user_id' => $owner->id, 'listing_id' => $listing->id, 'title' => 'One', 'amount' => 400000, 'n_o_days' => 20,
            'funding_duration' => 30, 'status' => 'to_do', 'pending_collected' => 400000, 'created_at' => now(), 'updated_at' => now()]);
        $m2 = DB::table('milestones')->insertGetId(['user_id' => $owner->id, 'listing_id' => $listing->id, 'title' => 'Two', 'amount' => 500000, 'n_o_days' => 20,
            'funding_duration' => 30, 'status' => 'locked', 'pending_collected' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $bid = fn ($who, $amount, $ms) => DB::table('business_bids')->insertGetId(['date' => now()->toDateString(), 'investor_id' => $who->id, 'business_id' => $listing->id,
            'owner_id' => $owner->id, 'type' => 'Monetary', 'method' => 'lipr', 'amount' => $amount, 'representation' => 1, 'ms_id' => $ms, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->postJson('/api/v1/bidsAccepted', ['bid_ids' => [$bid($investor, 400000, $m1)]])->assertOk();
        $this->assertSame(0, (int) $listing->fresh()->threshold_met); // milestone 1 is covered; milestone 2 has raised nothing yet

        // A partial bid on milestone 2 cannot be accepted yet
        DB::table('milestones')->where('id', $m2)->update(['pending_collected' => 100000]);
        $small = $bid($later, 100000, $m2);
        $this->actingAs($owner)->postJson('/api/v1/bidsAccepted', ['bid_ids' => [$small]])->assertStatus(422);
        $this->assertSame(0, DB::table('accepted_bids')->where('ms_id', $m2)->count());
    }

    /** The deal room's "Investors" counts people: one investor with bids on two milestones is one investor (it said 2). */
    public function test_an_investor_with_two_accepted_bids_counts_once(): void
    {
        config(['mail.default' => 'array']);
        $owner = User::factory()->create(['user_type_id' => 1]);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Solar kiosks', 'category' => 'Energy', 'location' => 'Nakuru',
            'investment_needed' => 300000, 'share' => 10, 'active' => 1, 'threshold_met' => 1]);
        $ms = [];
        foreach (['One', 'Two'] as $i => $title) {
            $ms[] = DB::table('milestones')->insertGetId(['user_id' => $owner->id, 'listing_id' => $listing->id, 'title' => $title, 'amount' => 150000, 'n_o_days' => 20,
                'funding_duration' => 30, 'status' => 'to_do', 'pending_collected' => 150000, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($ms as $m) {
            $id = DB::table('business_bids')->insertGetId(['date' => now()->toDateString(), 'investor_id' => $investor->id, 'business_id' => $listing->id,
                'owner_id' => $owner->id, 'type' => 'Monetary', 'method' => 'lipr', 'amount' => 150000, 'representation' => 5, 'ms_id' => $m, 'created_at' => now(), 'updated_at' => now()]);
            $this->actingAs($owner)->postJson('/api/v1/bidsAccepted', ['bid_ids' => [$id]])->assertOk();
        }

        $this->assertSame(2, DB::table('accepted_bids')->where('investor_id', $investor->id)->count());
        $this->assertSame(1, (int) $listing->fresh()->invest_count);
    }
}
