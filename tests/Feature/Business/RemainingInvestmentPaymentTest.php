<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** After the owner accepts a bid, the investor pays the remaining 75%: it must cover it, be their own payment, and confirm the bid once. */
class RemainingInvestmentPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function accepted(): array
    {
        config(['mail.default' => 'array']);
        $owner = User::factory()->create(['user_type_id' => 1]);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Water kiosks', 'category' => 'Water', 'location' => 'Nairobi', 'investment_needed' => 900000, 'share' => 15]);
        $acceptedId = DB::table('accepted_bids')->insertGetId(['bid_id' => 1, 'investor_id' => $investor->id, 'business_id' => $listing->id, 'owner_id' => $owner->id,
            'type' => 'Monetary', 'method' => 'lipr', 'amount' => 200000, 'representation' => 3.33, 'status' => 'awaiting_payment', 'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $investor, $acceptedId];
    }

    private function payment(int $userId, string $ref, float $usd): void
    {
        DB::table('lipr_payments')->insert(['reference_id' => $ref, 'transaction_id' => 'TX-'.$ref, 'user_id' => $userId, 'status' => 'successful', 'purpose' => 'awaiting_payment',
            'amount' => $usd * 130, 'amount_usd' => $usd, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_full_75_percent_confirms_the_bid_once_and_tells_the_owner(): void
    {
        [$owner, $investor, $acceptedId] = $this->accepted();
        $this->payment($investor->id, 'LIPR-REF-75', 150000); // 75% of 200,000

        $this->actingAs($investor)->postJson('/api/v1/lipr-status-bidsAwaiting', ['business_id' => $acceptedId, 'referenceId' => 'LIPR-REF-75'])->assertOk();

        $bid = DB::table('accepted_bids')->find($acceptedId);
        $this->assertSame('confirmed', $bid->status);
        $this->assertSame(1, (int) $bid->paid_in_full);
        $this->assertSame('LIPR-REF-75', $bid->lipr_transaction_id);
        $this->assertTrue(DB::table('notifications')->where('receiver_id', $owner->id)->where('text', 'like', '%paid the rest of their investment%')->exists());

        $this->actingAs($investor)->postJson('/api/v1/lipr-status-bidsAwaiting', ['business_id' => $acceptedId, 'referenceId' => 'LIPR-REF-75'])
            ->assertOk()->assertJsonPath('status', 'completed');

        // The investor is told once (a second poll sends nothing), with a link to My Investments
        $notices = DB::table('notifications')->where('receiver_id', $investor->id)->where('text', 'like', '%is confirmed.%')->get();
        $this->assertCount(1, $notices);
        $this->assertSame('my-investments', $notices[0]->link);
    }

    public function test_a_short_payment_or_someone_elses_payment_is_refused(): void
    {
        [, $investor, $acceptedId] = $this->accepted();
        $this->payment($investor->id, 'LIPR-SHORT', 1000);
        $this->actingAs($investor)->postJson('/api/v1/lipr-status-bidsAwaiting', ['business_id' => $acceptedId, 'referenceId' => 'LIPR-SHORT'])->assertStatus(422);

        $other = User::factory()->create(['user_type_id' => 2]);
        $this->payment($other->id, 'LIPR-OTHER', 150000);
        $this->actingAs($investor)->postJson('/api/v1/lipr-status-bidsAwaiting', ['business_id' => $acceptedId, 'referenceId' => 'LIPR-OTHER'])->assertForbidden();

        $this->assertSame('awaiting_payment', DB::table('accepted_bids')->find($acceptedId)->status);
    }

    public function test_the_last_75_percent_makes_its_milestone_fully_funded_at_once(): void
    {
        config(['mail.default' => 'array']);
        $owner = User::factory()->create(['user_type_id' => 1]);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Solar lanterns', 'category' => 'Energy', 'location' => 'Nairobi', 'investment_needed' => 65000, 'share' => 5, 'active' => 1, 'threshold_met' => 1]);
        $msId = DB::table('milestones')->insertGetId(['user_id' => $owner->id, 'listing_id' => $listing->id, 'title' => 'B1', 'amount' => 65000, 'n_o_days' => 30, 'funding_duration' => 30,
            'status' => 'to_do', 'funding_collected' => 65000, 'start_date' => now()->toDateString(), 'deadline_date' => now()->addDays(30)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $acceptedId = DB::table('accepted_bids')->insertGetId(['bid_id' => 1, 'ms_id' => $msId, 'investor_id' => $investor->id, 'business_id' => $listing->id, 'owner_id' => $owner->id,
            'type' => 'Monetary', 'method' => 'lipr', 'amount' => 65000, 'representation' => 5, 'status' => 'awaiting_payment', 'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('lipr_payments')->insert(['reference_id' => 'LIPR-B1', 'transaction_id' => 'TX-B1', 'user_id' => $investor->id, 'status' => 'successful', 'purpose' => 'awaiting_payment',
            'amount' => 51967, 'amount_usd' => 400, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($investor)->postJson('/api/v1/lipr-status-bidsAwaiting', ['business_id' => $acceptedId, 'referenceId' => 'LIPR-B1'])->assertOk();

        $milestone = DB::table('milestones')->find($msId);
        $this->assertSame(100, (int) $milestone->progress_percentage);
        $this->assertSame(1, (int) $milestone->is_funded);
    }
}
