<?php

namespace Tests\Feature\Support;

use App\Models\Auth\User;
use App\Models\Finance\Transactions;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Each transaction of a service booking says what it was for, and which side of the booking the viewer is on. */
class TransactionAboutTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_service_fee_and_a_milestone_payout_name_their_booking_and_service(): void
    {
        $customer = User::factory()->create(['user_type_id' => 4]);
        $provider = User::factory()->create(['user_type_id' => 3]);
        $service = Services::create(['user_id' => $provider->id, 'name' => 'One-on-One Coaching', 'price' => 15000, 'category' => 'Business Coach', 'location' => 'Nairobi', 'details' => 'Help', 'active' => 1]);
        $booking = ServiceBooking::create(['service_id' => $service->id, 'booker_id' => $customer->id, 'service_owner_id' => $provider->id, 'date' => now()->toDateString(), 'status' => 'paid', 'payment_reference' => 'PAY-1']);
        $milestone = ServiceBookingMilestone::create(['booking_id' => $booking->id, 'service_id' => $service->id, 'booker_id' => $customer->id, 'title' => 'Sessions one to three', 'amount' => 7500, 'release_reference' => 'REL-1']);
        $row = fn (array $o) => Transactions::create($o + ['method' => 'lipr', 'fee_amount' => 0, 'status' => 'settled']);
        $row(['user_id' => $customer->id, 'recipient_id' => $provider->id, 'type' => 'service_fee', 'direction' => 'debit', 'gross_amount' => 15000, 'net_amount' => 15000, 'reference_id' => 'PAY-1']);
        $row(['user_id' => $customer->id, 'recipient_id' => $provider->id, 'type' => 'service_milestone', 'direction' => 'credit', 'gross_amount' => 7500, 'net_amount' => 7500, 'reference_id' => 'REL-1']);
        $row(['user_id' => $customer->id, 'type' => 'deposit', 'direction' => 'credit', 'gross_amount' => 100, 'net_amount' => 100, 'reference_id' => 'DEP-1']);

        $mine = fn (User $u) => collect($this->actingAs($u, 'sanctum')->getJson('/api/v1/transactions?page=1&per_page=25')->assertOk()->json('data.items'))->keyBy('reference_id');

        // C4: the customer sees their payment into escrow, the provider the release to them (each once)
        $forCustomer = $mine($customer);
        $this->assertSame(['kind' => 'service_booking', 'booking_id' => $booking->id, 'service_id' => $service->id, 'service_name' => 'One-on-One Coaching', 'milestone_title' => null, 'viewer' => 'customer'], $forCustomer['PAY-1']['about']);
        $this->assertArrayNotHasKey('REL-1', $forCustomer->all());
        $this->assertNull($forCustomer['DEP-1']['about']);

        $forProvider = $mine($provider);
        $this->assertArrayNotHasKey('PAY-1', $forProvider->all());
        $this->assertSame('provider', $forProvider['REL-1']['about']['viewer']);
        $this->assertSame($booking->id, $forProvider['REL-1']['about']['booking_id']);
        $this->assertSame('Sessions one to three', $forProvider['REL-1']['about']['milestone_title']);
    }

    /** C9: a Business Coach payout is stored once per side; each side sees its own row once (paid through LIPR). */
    public function test_a_business_coach_payout_shows_once_for_each_side(): void
    {
        $owner = User::factory()->create(['user_type_id' => 4]);
        $coach = User::factory()->create(['user_type_id' => 6]);
        $row = fn (array $o) => Transactions::create($o + ['method' => 'lipr', 'fee_amount' => 0, 'status' => 'settled', 'gross_amount' => 3000, 'net_amount' => 3000, 'reference_id' => 'RP-1']);
        $row(['user_id' => $owner->id, 'recipient_id' => $coach->id, 'type' => 'reviewer_payment_sent', 'direction' => 'debit']);
        $row(['user_id' => $coach->id, 'recipient_id' => $owner->id, 'type' => 'reviewer_payment_received', 'direction' => 'credit']);

        $seen = fn (User $u) => collect($this->actingAs($u, 'sanctum')->getJson('/api/v1/transactions?page=1&per_page=25')->assertOk()->json('data.items'))
            ->map(fn ($t) => [$t['type'], $t['direction']])->all();
        $this->assertSame([['reviewer_payment_sent', 'debit']], $seen($owner));
        $this->assertSame([['reviewer_payment_received', 'credit']], $seen($coach));
    }
}
