<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Communication\Notifications;
use App\Models\Kyc\KycVerification;
use App\Models\Services\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A new booking's notice takes the provider to Service bookings, where they accept it (not to what they booked); the answer comes from the provider. */
class NewBookingNotificationLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_provider_is_sent_to_service_bookings(): void
    {
        $provider = User::factory()->create(['user_type_id' => 3]);
        KycVerification::create(['user_id' => $provider->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);
        $service = Services::create(['user_id' => $provider->id, 'name' => 'Business Plan', 'price' => 30000, 'category' => 'Business Planning', 'location' => 'Nairobi', 'details' => 'A plan', 'active' => 1]);
        $customer = User::factory()->create(['user_type_id' => 1]);

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/serviceBook', [
            'service_id' => $service->id, 'date' => now()->addDays(3)->toDateString(), 'note' => 'Please start soon',
        ])->assertOk();

        $notice = Notifications::where('receiver_id', $provider->id)->latest('id')->firstOrFail();
        $this->assertSame('dashboard.serviceProvider.serviceBookings', $notice->link);
        $this->assertSame($customer->id, (int) $notice->customer_id);

        // Accepting it tells the customer, from the provider (the sender is a person, never the service id)
        $booking = \App\Models\Services\ServiceBooking::where('booker_id', $customer->id)->firstOrFail();
        \App\Models\Services\Smilestones::create(['user_id' => $provider->id, 'listing_id' => $service->id, 'title' => 'Plan', 'amount' => 30000, 'n_o_days' => 7, 'status' => 'To Do']);
        $this->actingAs($provider, 'sanctum')->postJson('/api/v1/bookingAccepted', ['bid_ids' => [$booking->id]])->assertOk();
        $accepted = Notifications::where('receiver_id', $customer->id)->latest('id')->firstOrFail();
        $this->assertStringContainsString('has been accepted', $accepted->text);
        $this->assertSame($provider->id, (int) $accepted->customer_id);
    }
}
