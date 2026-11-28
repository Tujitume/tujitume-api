<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Finance\Transactions;
use App\Models\Kyc\KycVerification;
use App\Models\Misc\Setting;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Services;
use App\Service\LiprMpesa\LiprCallback;
use App\Service\LiprMpesa\LiprW2W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LIPR accepts a milestone release and later reports it failed: the provider's transactions must not count that release
 * (it never arrived), and approving again counts once, so they see 10,000 in, not 20,000.
 */
class ServiceReleaseFailedCallbackTest extends TestCase
{
    use RefreshDatabase;

    public int $n = 0;

    public function test_a_failed_release_is_not_money_in_and_the_retry_counts_once(): void
    {
        $provider = User::factory()->create(['user_type_id' => 3, 'lipr_wallet' => 'W-PROVIDER']);
        KycVerification::create(['user_id' => $provider->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);
        $customer = User::factory()->create(['user_type_id' => 1]);
        Setting::updateOrCreate(['key' => 'platform_lipr_wallet'], ['value' => 'W-TUJITUME']);
        $service = Services::create(['user_id' => $provider->id, 'name' => 'Plan', 'price' => 10000, 'category' => 'Business Planning', 'location' => 'Nairobi', 'details' => 'A plan', 'active' => 1]);
        $booking = ServiceBooking::create(['service_id' => $service->id, 'booker_id' => $customer->id, 'service_owner_id' => $provider->id, 'status' => 'paid', 'paid' => 1, 'date' => now()->toDateString()]);
        $m = ServiceBookingMilestone::create(['booking_id' => $booking->id, 'service_id' => $service->id, 'booker_id' => $customer->id, 'title' => 'Plan', 'amount' => 10000, 'status' => 'Done', 'active' => 1]);

        $test = $this;
        $this->app->instance(LiprW2W::class, new class($test) extends LiprW2W {
            public function __construct(private $test) {}
            public function send($amount, string $receiver_wallet, string $payer_wallet, $milestone = null, array $options = [])
            {
                return ['success' => true, 'reference' => 'REL-'.(++$this->test->n)];
            }
        });
        $approve = fn () => $this->actingAs($customer, 'sanctum')->postJson("/api/v1/services/bookings/{$booking->id}/milestones/{$m->id}/approve");
        $callback = fn (string $ref, string $status) => $this->postJson('/api/lipr-callback/service-milestone?sig='.LiprCallback::signature('/lipr-callback/service-milestone'),
            ['transaction' => ['reference' => $ref, 'id' => 'T-'.$ref, 'transactionStatus' => $status, 'amount' => 10000], 'metadata' => ['listingId' => $m->id]])->assertOk();

        $approve()->assertOk();
        $callback('REL-1', 'failed');
        $this->assertSame('failed', Transactions::where('reference_id', 'REL-1')->value('status'));
        $this->assertSame('in_progress', $booking->refresh()->status);

        $approve()->assertOk();
        $callback('REL-2', 'successful');
        $this->assertSame('completed', $booking->refresh()->status);

        $stats = $this->actingAs($provider, 'sanctum')->getJson('/api/v1/transactions?page=1&per_page=10')->assertOk()->json('data.stats');
        $this->assertEquals(10000, collect($stats['by_currency'])->sum('in_total'));
    }
}
