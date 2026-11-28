<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The M-Pesa poll that turns a paid 25% deposit into a bid: only when the deposit covers the bid, the investor's own payment only, safe to poll twice. */
class BidPaymentPollTest extends TestCase
{
    use RefreshDatabase;

    private function setUpBid(): array
    {
        config(['mail.default' => 'array']);
        $owner = User::factory()->create(['user_type_id' => 1, 'email' => 'owner@example.test']);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Water kiosks', 'category' => 'Water', 'location' => 'Nairobi', 'investment_needed' => 900000, 'share' => 15]);
        DB::table('lipr_payments')->insert(['reference_id' => 'REF-1', 'transaction_id' => 'TX-1', 'user_id' => $investor->id, 'status' => 'successful', 'purpose' => 'bids',
            'amount' => 66700, 'amount_usd' => 513, 'created_at' => now(), 'updated_at' => now()]); // the 25% deposit of a KSh 250,000 bid (62,500) plus the checkout's fees, in KSh

        return [$owner, $investor, $listing];
    }

    public function test_a_bid_needs_its_deposit_and_the_owner_is_emailed(): void
    {
        [$owner, $investor, $listing] = $this->setUpBid();

        // Claims a bigger bid than the deposit covers (KSh 66,700 covers 25% of 250,000, not of 999,999)
        $this->actingAs($investor)->postJson('/api/v1/lipr-status-bids', [
            'business_id' => $listing->id, 'referenceId' => 'REF-1', 'amountUSD' => 999999, 'amountPaid' => 66700, 'share' => 99,
        ])->assertStatus(422);
        $this->assertSame(0, DB::table('business_bids')->count());

        $this->actingAs($investor)->postJson('/api/v1/lipr-status-bids', [
            'business_id' => $listing->id, 'referenceId' => 'REF-1', 'amountUSD' => 250000, 'amountPaid' => 66700, 'share' => 99,
        ])->assertOk();

        $bid = DB::table('business_bids')->where('lipr_transaction_id', 'REF-1')->first();
        $this->assertSame(250000, (int) $bid->amount);
        $this->assertEqualsWithDelta(4.1667, (float) $bid->representation, 0.001); // 250,000 / 900,000 × 15 %, not the 99 sent

        $sentTo = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress());
        $this->assertContains('owner@example.test', $sentTo->all());

        // Polled again: the same answer, no second bid
        $this->actingAs($investor)->postJson('/api/v1/lipr-status-bids', ['business_id' => $listing->id, 'referenceId' => 'REF-1', 'amountUSD' => 250000, 'amountPaid' => 66700])
            ->assertOk()->assertJsonPath('status', 'completed');
        $this->assertSame(1, DB::table('business_bids')->where('lipr_transaction_id', 'REF-1')->count());
    }

    public function test_someone_else_cannot_turn_the_payment_into_their_bid(): void
    {
        [, , $listing] = $this->setUpBid();
        $other = User::factory()->create(['user_type_id' => 2]);

        $this->actingAs($other)->postJson('/api/v1/lipr-status-bids', ['business_id' => $listing->id, 'referenceId' => 'REF-1', 'amountUSD' => 250000, 'amountPaid' => 66700])
            ->assertForbidden();
        $this->assertSame(0, DB::table('business_bids')->count());
    }
}
