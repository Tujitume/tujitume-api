<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use App\Models\Services\ServiceBooking;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The provider can only accept before the booking's start day begins, so a booking for today was born
 * expired ("Reply time has passed", accept refused). The start date is now from tomorrow.
 */
class ServiceBookingStartDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_booking_starts_tomorrow_at_the_earliest_and_can_then_be_accepted(): void
    {
        $provider = User::factory()->create(['user_type_id' => 3]);
        KycVerification::create(['user_id' => $provider->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);
        $service = Services::create(['user_id' => $provider->id, 'name' => 'Bookkeeping', 'price' => 15000, 'category' => 'Finance', 'location' => 'Nairobi', 'details' => 'Books', 'active' => 1]);
        Smilestones::create(['user_id' => $provider->id, 'listing_id' => $service->id, 'title' => 'Kickoff', 'amount' => 15000, 'n_o_days' => 7, 'status' => 'To Do']);
        $customer = User::factory()->create(['user_type_id' => 1]);

        foreach ([now()->toDateString(), now()->subDay()->toDateString()] as $date) {
            $this->actingAs($customer, 'sanctum')->postJson('/api/v1/serviceBook', ['service_id' => $service->id, 'date' => $date])
                ->assertStatus(422)->assertJsonValidationErrors('date');
        }
        $this->assertSame(0, ServiceBooking::count());

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/serviceBook', ['service_id' => $service->id, 'date' => now()->addDay()->toDateString()])->assertOk();
        $booking = ServiceBooking::firstOrFail();
        $this->actingAs($provider, 'sanctum')->postJson('/api/v1/bookingAccepted', ['bid_ids' => [$booking->id]])->assertOk();
        $this->assertSame('confirmed', $booking->fresh()->status);

        // the customer's list: time left to pay while it is ahead, nothing once the start day has begun
        $row = $this->actingAs($customer, 'sanctum')->getJson('/api/v1/services/my_booking?page=1&per_page=10')->assertOk()->json('data.items.0');
        $this->assertMatchesRegularExpression('/^\d+ (hours?|minutes?)/', (string) $row['deadline']);
        $booking->update(['date' => now()->toDateString()]);
        $row = $this->actingAs($customer, 'sanctum')->getJson('/api/v1/services/my_booking?page=1&per_page=10')->assertOk()->json('data.items.0');
        $this->assertNull($row['deadline']);
    }

    public function test_a_cancelled_booking_no_longer_shows_the_service_as_booked(): void
    {
        $provider = User::factory()->create(['user_type_id' => 3]);
        $service = Services::create(['user_id' => $provider->id, 'name' => 'Bookkeeping', 'price' => 15000, 'category' => 'Finance', 'location' => 'Nairobi', 'details' => 'Books', 'active' => 1]);
        $customer = User::factory()->create(['user_type_id' => 1]);
        $booking = ServiceBooking::create(['service_id' => $service->id, 'booker_id' => $customer->id, 'service_owner_id' => $provider->id, 'date' => now()->addDays(2)->toDateString(), 'status' => 'pending']);

        $booked = fn () => collect($this->actingAs($customer, 'sanctum')->getJson('/api/v1/latServicesAuth?all=1')->json('data'))->firstWhere('id', $service->id)['booked'];
        $this->assertTrue($booked());
        $booking->update(['status' => 'cancelled']);
        $this->assertFalse($booked()); // Explore offers Book again, and serviceBook accepts it
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/serviceBook', ['service_id' => $service->id, 'date' => now()->addDays(3)->toDateString()])->assertOk();
    }
}
