<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Finance\LiprPayment;
use App\Models\Kyc\KycVerification;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Service Payment Received" after an M-Pesa payment: one email each, the customer's with what they really paid (service
 * fee plus checkout fees, never a made-up 3%), the provider's without the customer's fees.
 */
class ServicePaymentEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_and_provider_each_get_their_own_correct_email(): void
    {
        $provider = User::factory()->create(['user_type_id' => 3, 'email' => 'provider@example.test', 'lipr_wallet' => 'W-P']);
        KycVerification::create(['user_id' => $provider->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);
        $customer = User::factory()->create(['user_type_id' => 1, 'email' => 'customer@example.test', 'first_name' => 'Brian']);
        $service = Services::create(['user_id' => $provider->id, 'name' => 'Business Plan', 'price' => 30000, 'category' => 'Business Planning', 'location' => 'Nairobi', 'details' => 'A plan', 'active' => 1]);
        Smilestones::create(['user_id' => $provider->id, 'listing_id' => $service->id, 'title' => 'Workshop', 'amount' => 30000, 'n_o_days' => 7]);

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/serviceBook', ['service_id' => $service->id, 'date' => now()->addDays(3)->toDateString()])->assertOk();
        $booking = ServiceBooking::where('booker_id', $customer->id)->firstOrFail();
        $this->actingAs($provider, 'sanctum')->postJson('/api/v1/bookingAccepted', ['bid_ids' => [$booking->id]])->assertOk();
        app('mailer')->getSymfonyTransport()->flush();

        LiprPayment::create(['reference_id' => 'REF-E', 'transaction_id' => 'TX-E', 'user_id' => $customer->id, 'status' => 'successful', 'purpose' => 's_mile', 'amount' => 31980, 'amount_usd' => 246]);
        $first = ServiceBookingMilestone::where('booking_id', $booking->id)->orderBy('id')->first();
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/lipr-status-service', ['listing_id' => $first->id, 'referenceId' => 'REF-E'])->assertOk();

        $mails = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage())
            ->filter(fn ($m) => $m->getSubject() === 'Service Payment Received')->values();
        $this->assertCount(2, $mails);
        $byTo = $mails->keyBy(fn ($m) => $m->getTo()[0]->getAddress());
        $this->assertCount(1, $byTo['customer@example.test']->getTo());

        $customerHtml = $byTo['customer@example.test']->getHtmlBody();
        $this->assertStringContainsString('KES 30,000.00', $customerHtml);
        $this->assertStringContainsString('KES 1,980.00', $customerHtml);
        $this->assertStringContainsString('KES 31,980.00', $customerHtml);
        $this->assertStringNotContainsString('30900', $customerHtml);

        $providerHtml = $byTo['provider@example.test']->getHtmlBody();
        $this->assertStringContainsString('KES 30,000.00', $providerHtml);
        $this->assertStringNotContainsString('31,980', $providerHtml);
        $this->assertStringContainsString('serviceProvider.serviceBookings', $providerHtml);
    }
}
