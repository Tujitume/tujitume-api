<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use App\Models\Misc\Setting;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Services;
use App\Service\LiprMpesa\LiprW2W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paying a service audit fee whose wallet transfer fails: nothing is charged and the audit is back to "quoted", so the
 * customer can pay again or cancel (it used to stay at "paying" for good: no pay, no cancel).
 */
class ServiceAuditFeeFailureTest extends TestCase
{
    use RefreshDatabase;

    public bool $works = false;

    public function test_a_failed_fee_payment_can_be_paid_again_or_cancelled(): void
    {
        $pm = User::factory()->create(['user_type_id' => 3, 'lipr_wallet' => 'WALLET-PM']);
        $provider = User::factory()->create(['user_type_id' => 3, 'lipr_wallet' => 'WALLET-PROVIDER']);
        foreach ([$pm, $provider] as $u) KycVerification::create(['user_id' => $u->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);
        $customer = User::factory()->create(['user_type_id' => 1, 'lipr_wallet' => 'WALLET-CUSTOMER']);
        Setting::updateOrCreate(['key' => 'platform_lipr_wallet'], ['value' => 'WALLET-TUJITUME']);
        $service = Services::create(['user_id' => $provider->id, 'name' => 'Plan', 'price' => 12000, 'category' => 'Business Planning', 'location' => 'Nairobi', 'details' => 'A plan', 'active' => 1]);
        $booking = ServiceBooking::create(['service_id' => $service->id, 'booker_id' => $customer->id, 'service_owner_id' => $provider->id, 'status' => 'paid', 'paid' => 1, 'date' => now()->toDateString()]);
        $m = ServiceBookingMilestone::create(['booking_id' => $booking->id, 'service_id' => $service->id, 'booker_id' => $customer->id, 'title' => 'Model', 'amount' => 12000, 'status' => 'Done', 'active' => 1]);

        $test = $this;
        $this->app->instance(LiprW2W::class, new class($test) extends LiprW2W {
            public function __construct(private $test) {}
            public function send($amount, string $receiver_wallet, string $payer_wallet, $milestone = null, array $options = [])
            {
                return $this->test->works ? ['success' => true, 'reference' => 'TR-OK'] : ['success' => false, 'message' => 'Insufficient balance'];
            }
        });

        $url = "/api/v1/services/bookings/{$booking->id}/milestones/{$m->id}/audit";
        $this->actingAs($customer, 'sanctum')->postJson($url, ['auditor_id' => $pm->id])->assertOk();
        $this->actingAs($pm, 'sanctum')->postJson("/api/v1/pm-audits/service/{$m->id}/respond", ['action' => 'accept', 'fee' => 1500])->assertOk();

        $this->actingAs($customer, 'sanctum')->postJson($url.'/pay')->assertStatus(502);
        $this->assertSame('quoted', $m->fresh()->audit_status);

        // paid again once the wallet works
        $this->works = true;
        $this->actingAs($customer, 'sanctum')->postJson($url.'/pay')->assertOk();
        $this->assertSame(['accepted', 'held'], [$m->fresh()->audit_status, $m->fresh()->audit_fee_status]);
    }
}
