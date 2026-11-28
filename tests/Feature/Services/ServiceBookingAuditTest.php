<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Finance\Transactions;
use App\Models\Kyc\KycVerification;
use App\Models\Misc\Setting;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Services;
use App\Service\LiprMpesa\LiprW2W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** DECISION-9: a customer may have a "Done" booking milestone audited (paid) by a project manager before approving it. */
class ServiceBookingAuditTest extends TestCase
{
    use RefreshDatabase;

    public array $transfers = [];
    private User $provider;
    private User $customer;
    private User $pm;
    private ServiceBooking $booking;
    private ServiceBookingMilestone $milestone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->provider('WALLET-PROVIDER');
        $this->pm = $this->provider('WALLET-PM');
        $this->customer = User::factory()->create(['user_type_id' => 4, 'first_name' => 'Grace', 'lipr_wallet' => 'WALLET-GRACE']);
        Setting::updateOrCreate(['key' => 'platform_lipr_wallet'], ['value' => 'WALLET-TUJITUME']);

        $service = Services::create(['user_id' => $this->provider->id, 'name' => 'Website Design', 'price' => 10000, 'category' => 'IT', 'location' => 'Nairobi', 'details' => 'A site', 'active' => 1]);
        $this->booking = ServiceBooking::create(['service_id' => $service->id, 'booker_id' => $this->customer->id, 'service_owner_id' => $this->provider->id, 'status' => 'paid', 'paid' => 1, 'date' => now()->toDateString()]);
        $this->milestone = ServiceBookingMilestone::create(['booking_id' => $this->booking->id, 'service_id' => $service->id, 'booker_id' => $this->customer->id, 'title' => 'Design', 'amount' => 4000, 'status' => 'Done', 'active' => 1]);

        $test = $this;
        $this->app->instance(LiprW2W::class, new class($test) extends LiprW2W {
            public function __construct(private $test) {}
            public function send($amount, string $receiver_wallet, string $payer_wallet, $milestone = null, array $options = [])
            {
                $this->test->transfers[] = compact('amount', 'receiver_wallet', 'payer_wallet');
                return ['success' => true, 'reference' => 'TR-'.count($this->test->transfers)];
            }
        });
    }

    private function provider(string $wallet): User
    {
        $user = User::factory()->create(['user_type_id' => 3, 'lipr_wallet' => $wallet]);
        KycVerification::create(['user_id' => $user->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);

        return $user;
    }

    private function url(string $tail = ''): string
    {
        return "/api/v1/services/bookings/{$this->booking->id}/milestones/{$this->milestone->id}/audit{$tail}";
    }

    public function test_the_customer_pays_the_audit_into_escrow_and_decides_after_the_report(): void
    {
        $this->actingAs($this->provider, 'sanctum')->postJson($this->url(), ['auditor_id' => $this->pm->id])->assertStatus(403);
        $this->actingAs($this->customer, 'sanctum')->postJson($this->url(), ['auditor_id' => $this->provider->id])->assertStatus(422);
        $flow = $this->actingAs($this->customer, 'sanctum')->postJson($this->url(), ['auditor_id' => $this->pm->id, 'notes' => 'Check the pages work'])->assertOk()->json('data');
        $this->assertSame('pending', $flow['milestones'][0]['audit']['status']);
        $this->assertFalse($flow['milestones'][0]['can_approve']);

        // approve and request changes wait for the audit
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/services/bookings/{$this->booking->id}/milestones/{$this->milestone->id}/approve")->assertStatus(409);

        // the PM sees it on the final approval page and quotes a fee
        $rows = $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/pm-audits/final_approval')->assertOk()->json('data');
        $this->assertSame('service', $rows[0]['source']);
        $this->actingAs($this->pm, 'sanctum')->postJson("/api/v1/pm-audits/service/{$this->milestone->id}/respond", ['action' => 'accept', 'fee' => 1500])->assertOk();
        $this->assertSame('quoted', $this->milestone->fresh()->audit_status);

        $this->actingAs($this->customer, 'sanctum')->postJson($this->url('/pay'))->assertOk();
        // a second pay (double click) is refused and takes nothing more
        $this->actingAs($this->customer, 'sanctum')->postJson($this->url('/pay'))->assertStatus(409);
        $this->assertCount(1, $this->transfers);
        $m = $this->milestone->fresh();
        $this->assertSame(['accepted', 'held'], [$m->audit_status, $m->audit_fee_status]);
        $this->assertSame(['amount' => 1500, 'receiver_wallet' => 'WALLET-TUJITUME', 'payer_wallet' => 'WALLET-GRACE'], $this->transfers[0]);

        $this->actingAs($this->pm, 'sanctum')->postJson("/api/v1/pm-audits/service/{$m->id}/report", ['recommendation' => 'approve', 'notes' => 'All pages load and match the brief.'])->assertOk();
        $m->refresh();
        $this->assertSame(['reported', 'released', 'approve'], [$m->audit_status, $m->audit_fee_status, $m->audit_recommendation]);
        $this->assertSame(['amount' => 1500, 'receiver_wallet' => 'WALLET-PM', 'payer_wallet' => 'WALLET-TUJITUME'], $this->transfers[1]);
        $this->assertSame('credit', Transactions::where('type', 'pm_audit_fee')->where('user_id', $this->pm->id)->value('direction'));
        $this->assertSame('debit', Transactions::where('type', 'pm_audit_fee')->where('user_id', $this->customer->id)->value('direction'));

        // the customer decides
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/services/bookings/{$this->booking->id}/milestones/{$this->milestone->id}/approve")->assertOk();
    }

    public function test_the_customer_cancels_an_audit_and_the_held_fee_is_refunded(): void
    {
        $this->actingAs($this->customer, 'sanctum')->postJson($this->url(), ['auditor_id' => $this->pm->id])->assertOk();
        $this->actingAs($this->pm, 'sanctum')->postJson("/api/v1/pm-audits/service/{$this->milestone->id}/respond", ['action' => 'accept', 'fee' => 1500])->assertOk();
        $this->actingAs($this->customer, 'sanctum')->postJson($this->url('/pay'))->assertOk();

        $this->actingAs($this->customer, 'sanctum')->postJson($this->url('/cancel'))->assertOk();
        $this->actingAs($this->customer, 'sanctum')->postJson($this->url('/cancel'))->assertStatus(409); // refunded once
        $this->assertCount(2, $this->transfers);
        $m = $this->milestone->fresh();
        $this->assertSame(['cancelled', 'refunded'], [$m->audit_status, $m->audit_fee_status]);
        $this->assertSame(['amount' => 1500, 'receiver_wallet' => 'WALLET-GRACE', 'payer_wallet' => 'WALLET-TUJITUME'], $this->transfers[1]);

        // the PM cannot report any more; the customer can decide or ask again
        $this->actingAs($this->pm, 'sanctum')->postJson("/api/v1/pm-audits/service/{$m->id}/report", ['recommendation' => 'approve', 'notes' => 'All pages load and match the brief.'])->assertStatus(409);
        $flow = $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/services/bookings/{$this->booking->id}/flow")->json('data');
        $this->assertTrue($flow['milestones'][0]['can_approve']);
        $this->assertTrue($flow['milestones'][0]['can_request_audit']);
    }

    public function test_a_declined_audit_costs_nothing_and_leaves_the_customer_free_to_decide(): void
    {
        $this->actingAs($this->customer, 'sanctum')->postJson($this->url(), ['auditor_id' => $this->pm->id])->assertOk();
        $this->actingAs($this->pm, 'sanctum')->postJson("/api/v1/pm-audits/service/{$this->milestone->id}/respond", ['action' => 'decline'])->assertOk();
        $this->assertSame('declined', $this->milestone->fresh()->audit_status);
        $this->assertSame([], $this->transfers);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/services/bookings/{$this->booking->id}/milestones/{$this->milestone->id}/approve")->assertOk();
    }
}
