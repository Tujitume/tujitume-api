<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use App\Models\Services\ServiceBooking;
use App\Models\Services\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every kind of account that can buy a service books it, sees it in its own bookings and opens its drawer data:
 * business owner (1), investor (2), another service provider (3), organization (4) and capital (5).
 */
class BookingEveryAccountTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_account_type_can_book_see_and_open_a_booking(): void
    {
        $provider = User::factory()->create(['user_type_id' => 3]);
        KycVerification::create(['user_id' => $provider->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);
        $service = Services::create(['user_id' => $provider->id, 'name' => 'Coaching', 'price' => 15000, 'category' => 'Business Coach', 'location' => 'Nairobi', 'details' => 'Help', 'active' => 1]);

        foreach ([1, 2, 3, 4, 5] as $type) {
            $buyer = User::factory()->create(['user_type_id' => $type]);
            // A service provider's own pages need KYC, as in the app
            if ($type === 3) KycVerification::create(['user_id' => $buyer->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);

            $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/serviceBook', [
                'service_id' => $service->id, 'date' => now()->addDays(5)->toDateString(), 'note' => "Booked by type $type",
            ])->assertOk();
            $booking = ServiceBooking::where('booker_id', $buyer->id)->firstOrFail();

            $mine = $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/services/my_booking?page=1&per_page=10')->assertOk();
            $this->assertContains($booking->id, collect($mine->json('data.items'))->pluck('id')->all(), "type $type does not see its booking");

            $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/services/bookings/{$booking->id}/flow")->assertOk()
                ->assertJsonPath('data.role', 'customer');
        }

        // The provider sees all five requests on Service bookings, and opens each as the provider
        $incoming = $this->actingAs($provider, 'sanctum')->getJson('/api/v1/services/service_booking?page=1&per_page=25')->assertOk();
        $this->assertCount(5, $incoming->json('data.items'));
        $any = ServiceBooking::first();
        $this->actingAs($provider, 'sanctum')->getJson("/api/v1/services/bookings/{$any->id}/flow")->assertOk()->assertJsonPath('data.role', 'provider');
    }
}
