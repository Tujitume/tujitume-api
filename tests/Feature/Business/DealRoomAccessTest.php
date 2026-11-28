<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The business deal room: its owner and investors only; investors see the businesses they invested in; a message tells the other side. */
class DealRoomAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_owner_and_investors_use_the_deal_room(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->buildTemporaryUrlsUsing(fn ($path) => 'https://files.test/'.$path.'?signed=1');
        $owner = User::factory()->create(['user_type_id' => 1]);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $stranger = User::factory()->create(['user_type_id' => 2]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Kiosks', 'category' => 'Water', 'location' => 'Nairobi', 'investment_needed' => 10000, 'share' => 10, 'active' => 1]);
        $msId = DB::table('milestones')->insertGetId(['user_id' => $owner->id, 'listing_id' => $listing->id, 'title' => 'Kiosk 2', 'amount' => 10000, 'n_o_days' => 14, 'funding_duration' => 30,
            'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accepted_bids')->insert(['bid_id' => 1, 'ms_id' => $msId, 'investor_id' => $investor->id, 'business_id' => $listing->id, 'owner_id' => $owner->id, 'type' => 'Monetary', 'method' => 'lipr',
            'amount' => 10000, 'status' => 'confirmed', 'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $reqId = DB::table('milestone_pre_release_requests')->insertGetId(['milestone_id' => $msId, 'investor_id' => $investor->id, 'weight' => 0, 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now()]);
        $docId = DB::table('milestone_pre_release_docs')->insertGetId(['request_id' => $reqId, 'written_statement' => 'ok', 'quotation' => 'files/pre/quote.pdf', 'created_at' => now(), 'updated_at' => now()]);

        // The investor's deal room lists the business they invested in
        $this->actingAs($investor)->getJson('/api/v1/deal-room/businesses')->assertOk()->assertJsonPath('listings.0.id', $listing->id)->assertJsonPath('listings.0.deal_room_role', 'investor');
        $this->actingAs($stranger)->getJson('/api/v1/deal-room/businesses')->assertOk()->assertJsonCount(0, 'listings');

        // Conversation: strangers neither read nor write; the owner's message tells the investor
        $this->actingAs($stranger)->getJson("/api/v1/deal-room/milestone/{$msId}/communications")->assertNotFound();
        $this->actingAs($stranger)->postJson('/api/v1/deal-room/milestone/communications', ['message' => 'hi', 'milestone_id' => $msId, 'type' => 'text'])->assertNotFound();
        $this->actingAs($owner)->postJson('/api/v1/deal-room/milestone/communications', ['message' => 'Quotes are up.', 'milestone_id' => $msId, 'type' => 'text', 'sender_type' => 'admin'])->assertOk();
        $this->assertSame('owner', DB::table('milestone_communications')->value('sender_type')); // not the 'admin' sent
        $this->assertTrue(DB::table('notifications')->where('receiver_id', $investor->id)->where('text', 'like', '%wrote in the deal room%')->exists());
        $this->actingAs($investor)->getJson("/api/v1/deal-room/milestone/{$msId}/communications")->assertOk()->assertJsonCount(1, 'data');

        // Evidence files: a signed link for the investor, nothing for a stranger
        $this->actingAs($investor)->getJson("/api/v1/deal-room/milestone/{$msId}/file?kind=pre_release&id={$docId}&field=quotation")
            ->assertOk()->assertJsonPath('url', 'https://files.test/files/pre/quote.pdf?signed=1');
        $this->actingAs($stranger)->getJson("/api/v1/deal-room/milestone/{$msId}/file?kind=pre_release&id={$docId}&field=quotation")->assertNotFound();
        $this->actingAs($investor)->getJson("/api/v1/deal-room/milestone/{$msId}/file?kind=pre_release&id={$docId}&field=written_statement")->assertNotFound();
    }
}
