<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** An investor offers equipment: owner accepts → investor asks to verify → owner verifies → investor releases. Each step by the right person only. */
class AssetInvestmentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_asset_flow_runs_in_order_and_only_for_the_right_people(): void
    {
        Storage::fake('s3');
        config(['mail.default' => 'array']);
        $owner = User::factory()->create(['user_type_id' => 1]);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $otherOwner = User::factory()->create(['user_type_id' => 1]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Water kiosks', 'category' => 'Water', 'location' => 'Nairobi', 'investment_needed' => 100000, 'share' => 10, 'active' => 1]);
        $msId = DB::table('milestones')->insertGetId(['user_id' => $owner->id, 'listing_id' => $listing->id, 'title' => 'M1', 'amount' => 100000, 'n_o_days' => 7, 'funding_duration' => 30,
            'status' => 'to_do', 'pending_collected' => 0, 'funding_collected' => 0, 'created_at' => now(), 'updated_at' => now()]);

        // The owner cannot invest in their own business
        $this->actingAs($owner)->postJson('/api/v1/bidCommitsEQP', ['listing_id' => $listing->id, 'amount' => 1000, 'serial' => 'X'])->assertForbidden();

        $this->actingAs($investor)->postJson('/api/v1/bidCommitsEQP', ['listing_id' => $listing->id, 'amount' => 100000, 'percent' => 99, 'serial' => 'UV-FILTER-2231'])->assertOk();
        $bid = DB::table('business_bids')->where('investor_id', $investor->id)->first();
        $this->assertSame('Asset', $bid->type);
        $this->assertEqualsWithDelta(10.0, (float) $bid->representation, 0.001); // server share, not the 99 sent
        $this->assertSame($msId, (int) $bid->ms_id);
        $this->assertSame(1, (int) $listing->refresh()->threshold_met);

        $this->actingAs($owner)->postJson('/api/v1/bidsAccepted', ['bid_ids' => [$bid->id]])->assertOk();
        $accepted = DB::table('accepted_bids')->where('investor_id', $investor->id)->first();
        $this->assertSame('under_verification', $accepted->status);

        // Releasing before the owner verifies is refused; only the investor asks for verification; only the owner verifies
        $this->actingAs($investor)->getJson("/api/v1/releaseEquipment/{$listing->id}/0/".base64_encode($accepted->id))->assertStatus(422);
        $this->actingAs($otherOwner)->getJson("/api/v1/business/requestOwnerToVerify/{$accepted->id}")->assertNotFound();
        $this->actingAs($investor)->getJson("/api/v1/business/requestOwnerToVerify/{$accepted->id}")->assertOk();
        $this->actingAs($otherOwner)->getJson("/api/v1/business/markAsVerified/{$accepted->id}")->assertNotFound();
        $this->actingAs($owner)->getJson("/api/v1/business/markAsVerified/{$accepted->id}")->assertOk();

        $this->assertSame('verified', DB::table('accepted_bids')->find($accepted->id)->status);
        $this->assertSame(100000.0, (float) DB::table('milestones')->find($msId)->funding_collected);
        // The "you can release it now" notice goes to the investor
        $this->assertTrue(DB::table('notifications')->where('receiver_id', $investor->id)->where('text', 'like', '%can now release the equipment%')->exists());

        // The investor releases it: the owner is told and the investment is marked released (this answered 500, on a
        // createNotification method the controller does not have, and stayed "verified")
        $this->actingAs($investor)->getJson("/api/v1/releaseEquipment/{$listing->id}/0/".base64_encode($accepted->id))->assertOk();
        $this->assertSame('equipment_released', DB::table('accepted_bids')->find($accepted->id)->status);
        $this->assertTrue(DB::table('notifications')->where('receiver_id', $owner->id)->where('text', 'like', '%has been released%')->exists());
    }
}
