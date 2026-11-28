<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two investors share a business 75% / 25% over two milestones (KSh 200,000 each, 20% equity for KSh 400,000). The
 * owner accepts both: a bid that spills into the next milestone splits its equity with its money, and the pending money
 * leaves the milestone it was placed on. It used to give both rows of the spilled bid the whole 15% (35% of a 20% offer)
 * and leave KSh 200,000 pending on milestone 1 and -100,000 on milestone 2.
 */
class AcceptSplitBidsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_75_25_split_keeps_the_equity_and_pending_money_right(): void
    {
        config(['mail.default' => 'array']);
        $owner = User::factory()->create(['user_type_id' => 1]);
        $one = User::factory()->create(['user_type_id' => 2]);
        $two = User::factory()->create(['user_type_id' => 2]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Solar lanterns', 'category' => 'Energy', 'location' => 'Nairobi',
            'investment_needed' => 400000, 'share' => 20, 'active' => 1, 'threshold_met' => 1]);
        $ms = [];
        foreach (['A1', 'A2'] as $title) {
            $ms[] = DB::table('milestones')->insertGetId(['user_id' => $owner->id, 'listing_id' => $listing->id, 'title' => $title, 'amount' => 200000, 'n_o_days' => 30,
                'funding_duration' => 30, 'status' => 'to_do', 'pending_collected' => $title === 'A1' ? 400000 : 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        $bid = fn ($investor, $amount, $share) => DB::table('business_bids')->insertGetId(['date' => now()->toDateString(), 'investor_id' => $investor->id, 'business_id' => $listing->id,
            'owner_id' => $owner->id, 'type' => 'Monetary', 'method' => 'lipr', 'amount' => $amount, 'representation' => $share, 'ms_id' => $ms[0], 'created_at' => now(), 'updated_at' => now()]);
        $first = $bid($one, 300000, 15);
        $second = $bid($two, 100000, 5);

        $this->actingAs($owner)->postJson('/api/v1/bidsAccepted', ['bid_ids' => [$first]])->assertOk();
        $this->actingAs($owner)->postJson('/api/v1/bidsAccepted', ['bid_ids' => [$second]])->assertOk();

        $rows = DB::table('accepted_bids')->orderBy('id')->get(['investor_id', 'ms_id', 'amount', 'representation']);
        $this->assertSame([[$one->id, $ms[0], 200000.0, 10.0], [$one->id, $ms[1], 100000.0, 5.0], [$two->id, $ms[1], 100000.0, 5.0]],
            $rows->map(fn ($r) => [(int) $r->investor_id, (int) $r->ms_id, (float) $r->amount, (float) $r->representation])->all());
        $this->assertEquals(20.0, $rows->sum('representation')); // the whole offer, no more

        $milestones = DB::table('milestones')->whereIn('id', $ms)->orderBy('id')->get();
        $this->assertSame([0.0, 0.0], $milestones->map(fn ($m) => (float) $m->pending_collected)->all());
        $this->assertSame([200000.0, 200000.0], $milestones->map(fn ($m) => (float) $m->funding_collected)->all());
        $this->assertEquals(400000, (float) $listing->fresh()->amount_collected);
    }
}
