<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Service\LiprMpesa\LiprW2W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** An investor withdraws their own bid (deposit refunded by LIPR first) or their accepted investment before its money is released. */
class WithdrawBidTest extends TestCase
{
    use RefreshDatabase;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array']);
        DB::table('settings')->insert(['key' => 'platform_lipr_wallet', 'value' => 'PLATFORM-W', 'created_at' => now(), 'updated_at' => now()]);
        $test = $this;
        $this->app->instance(LiprW2W::class, new class($test) extends LiprW2W {
            public function __construct(private $test) {}
            public function send($amount, string $receiver_wallet, string $payer_wallet, $milestone = null, array $options = []) {
                $this->test->recordSend([$amount, $receiver_wallet, $payer_wallet]);
                return ['success' => true, 'reference' => 'REFUND-1'];
            }
        });
    }

    public function recordSend(array $call): void { $this->sent[] = $call; }

    private function world(): array
    {
        $owner = User::factory()->create(['user_type_id' => 1]);
        $investor = User::factory()->create(['user_type_id' => 2, 'lipr_wallet' => 'INV-W']);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Water kiosks', 'category' => 'Water', 'location' => 'Nairobi', 'investment_needed' => 900000, 'share' => 15]);
        $msId = DB::table('milestones')->insertGetId(['user_id' => $owner->id, 'listing_id' => $listing->id, 'title' => 'M1', 'amount' => 400000, 'n_o_days' => 7, 'funding_duration' => 30,
            'status' => 'to_do', 'pending_collected' => 250000, 'funding_collected' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $investor, $listing, $msId];
    }

    public function test_a_pending_bid_is_refunded_then_removed_and_only_by_its_investor(): void
    {
        [, $investor, $listing, $msId] = $this->world();
        DB::table('lipr_payments')->insert(['reference_id' => 'DEP-1', 'transaction_id' => 'TX', 'user_id' => $investor->id, 'purpose' => 'bids', 'status' => 'completed', 'amount' => 8125000, 'amount_usd' => 62500, 'created_at' => now(), 'updated_at' => now()]);
        $bidId = DB::table('business_bids')->insertGetId(['date' => now()->toDateString(), 'investor_id' => $investor->id, 'business_id' => $listing->id, 'owner_id' => $listing->user_id, 'ms_id' => $msId,
            'type' => 'Monetary', 'method' => 'lipr', 'amount' => 250000, 'representation' => 4.17, 'lipr_transaction_id' => 'DEP-1', 'created_at' => now(), 'updated_at' => now()]);

        $stranger = User::factory()->create(['user_type_id' => 2]);
        $this->actingAs($stranger)->getJson("/api/v1/business/remove_bids/{$bidId}")->assertNotFound();
        $this->assertSame([], $this->sent);

        $this->actingAs($investor)->getJson("/api/v1/business/remove_bids/{$bidId}")->assertOk();
        $this->assertSame([[8125000, 'INV-W', 'PLATFORM-W']], $this->sent); // the deposit back, from the platform wallet
        $this->assertNull(DB::table('business_bids')->find($bidId));
        $this->assertSame(0.0, (float) DB::table('milestones')->find($msId)->pending_collected);
    }

    public function test_an_investment_cannot_be_withdrawn_once_its_milestone_money_is_released(): void
    {
        [, $investor, $listing, $msId] = $this->world();
        DB::table('milestones')->where('id', $msId)->update(['fund_released_75' => 1]);
        $accId = DB::table('accepted_bids')->insertGetId(['bid_id' => 1, 'ms_id' => $msId, 'investor_id' => $investor->id, 'business_id' => $listing->id, 'owner_id' => $listing->user_id,
            'type' => 'Monetary', 'method' => 'lipr', 'amount' => 250000, 'status' => 'confirmed', 'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        $other = User::factory()->create(['user_type_id' => 2]);
        $this->actingAs($other)->getJson("/api/v1/business/withdraw_investment/{$accId}")->assertNotFound();
        $this->actingAs($investor)->getJson("/api/v1/business/withdraw_investment/{$accId}")->assertStatus(409);
        $this->assertNotNull(DB::table('accepted_bids')->find($accId));
        $this->assertSame([], $this->sent);
    }

    public function test_the_owner_rejecting_an_mpesa_bid_refunds_the_whole_deposit(): void
    {
        [$owner, $investor, $listing, $msId] = $this->world();
        DB::table('lipr_payments')->insert(['reference_id' => 'DEP-2', 'transaction_id' => 'TX', 'user_id' => $investor->id, 'purpose' => 'bids', 'status' => 'completed', 'amount' => 8125000, 'amount_usd' => 62500, 'created_at' => now(), 'updated_at' => now()]);
        $bidId = DB::table('business_bids')->insertGetId(['date' => now()->toDateString(), 'investor_id' => $investor->id, 'business_id' => $listing->id, 'owner_id' => $owner->id, 'ms_id' => $msId,
            'type' => 'Monetary', 'method' => 'lipr', 'amount' => 250000, 'representation' => 4.17, 'lipr_transaction_id' => 'DEP-2', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->postJson('/api/v1/bidsAccepted', ['bid_ids' => [$bidId], 'reject' => true])->assertOk();

        $this->assertSame([[8125000, 'INV-W', 'PLATFORM-W']], $this->sent); // all of it back (the owner's choice, nothing kept)
        $this->assertNull(DB::table('business_bids')->find($bidId));
        $this->assertTrue(DB::table('notifications')->where('receiver_id', $investor->id)->where('text', 'like', '%deposit you paid has been refunded%')->exists());
        // The milestone is no longer covered: investors and the owner are told, each with a link into their own pages
        $this->assertSame('investment-bids', DB::table('notifications')->where('receiver_id', $owner->id)->where('text', 'like', '%yet to meet the threshold%')->value('link'));
        $this->assertNotContains('/', DB::table('notifications')->whereIn('receiver_id', [$owner->id, $investor->id])->pluck('link')->all());
    }
}
