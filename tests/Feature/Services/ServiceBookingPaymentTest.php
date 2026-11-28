<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Finance\LiprPayment;
use App\Models\Kyc\KycVerification;
use App\Models\Misc\Setting;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use App\Service\LiprMpesa\LiprW2W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Paying for a booked service and releasing it milestone by milestone (ServiceBookingFlow). */
class ServiceBookingPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;
    private User $customer;
    private Services $service;
    /** @var array<int, array> transfers the fake wallet was asked for */
    private array $transfers = [];
    private bool $transferWorks = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = User::factory()->create(['user_type_id' => 3, 'lipr_wallet' => 'WALLET-PROVIDER']);
        KycVerification::create(['user_id' => $this->provider->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);
        $this->customer = User::factory()->create(['user_type_id' => 4, 'first_name' => 'Grace']);
        Setting::updateOrCreate(['key' => 'platform_lipr_wallet'], ['value' => 'WALLET-TUJITUME']);

        $this->service = Services::create(['user_id' => $this->provider->id, 'name' => 'Website Design', 'price' => 10000, 'category' => 'IT', 'location' => 'Nairobi', 'details' => 'A site', 'active' => 1]);
        Smilestones::create(['user_id' => $this->provider->id, 'listing_id' => $this->service->id, 'title' => 'Design', 'amount' => 4000, 'n_o_days' => 5]);
        Smilestones::create(['user_id' => $this->provider->id, 'listing_id' => $this->service->id, 'title' => 'Build', 'amount' => 6000, 'n_o_days' => 10]);

        // No real money moves in tests: the wallet transfer is recorded instead
        $test = $this;
        $this->app->instance(LiprW2W::class, new class($test) extends LiprW2W {
            public function __construct(private $test) {}
            public function send($amount, string $receiver_wallet, string $payer_wallet, $milestone = null, array $options = [])
            {
                return $this->test->recordTransfer(compact('amount', 'receiver_wallet', 'payer_wallet', 'options'));
            }
        });
    }

    public function recordTransfer(array $transfer): array
    {
        $this->transfers[] = $transfer;

        return $this->transferWorks ? ['success' => true, 'reference' => 'TR-'.count($this->transfers)] : ['success' => false, 'message' => 'Wallet offline'];
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    private function book(): ServiceBooking
    {
        $this->as($this->customer)->postJson('/api/v1/serviceBook', ['service_id' => $this->service->id, 'date' => now()->addDays(3)->toDateString()])->assertOk();

        return ServiceBooking::where('booker_id', $this->customer->id)->firstOrFail();
    }

    private function acceptedBooking(): ServiceBooking
    {
        $booking = $this->book();
        $this->as($this->provider)->postJson('/api/v1/bookingAccepted', ['bid_ids' => [$booking->id]])->assertOk();

        return $booking->refresh();
    }

    private function pay(ServiceBooking $booking, string $reference = 'REF-1', float $amount = 10500): \Illuminate\Testing\TestResponse
    {
        LiprPayment::create(['reference_id' => $reference, 'transaction_id' => 'TX-'.$reference, 'user_id' => $this->customer->id, 'status' => 'successful', 'purpose' => 's_mile', 'amount' => $amount, 'amount_usd' => $amount]);
        $first = ServiceBookingMilestone::where('booking_id', $booking->id)->orderBy('id')->first();

        return $this->as($this->customer)->postJson('/api/v1/lipr-status-service', ['listing_id' => $first->id, 'referenceId' => $reference]);
    }

    private function milestones(ServiceBooking $booking)
    {
        return ServiceBookingMilestone::where('booking_id', $booking->id)->orderBy('id')->get();
    }

    private function move(ServiceBookingMilestone $m, string $status, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->as($as ?? $this->provider)->postJson('/api/v1/services/mile_s_status', ['id' => $m->id, 'status' => $status]);
    }

    public function test_only_the_provider_accepts_a_booking_once_and_its_milestones_get_deadlines(): void
    {
        $booking = $this->book();
        $stranger = User::factory()->create(['user_type_id' => 1]);

        $this->as($stranger)->postJson('/api/v1/bookingAccepted', ['bid_ids' => [$booking->id]])->assertOk();
        $this->assertSame('pending', $booking->refresh()->status);
        $this->as($stranger)->postJson('/api/v1/bookingRejected', ['bid_ids' => [$booking->id]]);
        $this->assertSame('pending', $booking->refresh()->status);

        $this->as($this->provider)->postJson('/api/v1/bookingAccepted', ['bid_ids' => [$booking->id]])->assertOk();
        $this->as($this->provider)->postJson('/api/v1/bookingAccepted', ['bid_ids' => [$booking->id]])->assertOk();
        $this->assertSame('confirmed', $booking->refresh()->status);
        $milestones = $this->milestones($booking);
        $this->assertCount(2, $milestones);
        // One after the other: Design (5 days) is due 5 days after the service date, Build (10 days) 10 days after that
        $start = \Illuminate\Support\Carbon::parse($booking->date)->startOfDay();
        $this->assertSame($start->copy()->addDays(5)->toDateString(), \Illuminate\Support\Carbon::parse($milestones[0]->deadline)->toDateString());
        $this->assertSame($start->copy()->addDays(15)->toDateString(), \Illuminate\Support\Carbon::parse($milestones[1]->deadline)->toDateString());

        $flow = $this->as($this->customer)->getJson("/api/v1/services/bookings/{$booking->id}/flow")->assertOk()->json('data');
        $this->assertSame('awaiting_payment', $flow['state']);
        $this->assertTrue($flow['can_pay']);
        $this->assertSame($milestones[0]->id, $flow['pay_milestone_id']);
        $this->as($stranger)->getJson("/api/v1/services/bookings/{$booking->id}/flow")->assertNotFound();
    }

    public function test_paying_records_the_booking_once_and_starts_the_first_milestone(): void
    {
        $booking = $this->acceptedBooking();
        // The provider cannot start the work before it is paid
        $this->move($this->milestones($booking)[0], 'In Progress')->assertStatus(422);

        $this->pay($booking)->assertOk()->assertJson(['status' => 'completed']);
        $booking->refresh();
        $this->assertTrue((bool) $booking->paid);
        $this->assertSame('paid', $booking->status);
        $this->assertSame('REF-1', $booking->payment_reference);
        $this->assertSame('In Progress', $this->milestones($booking)[0]->status);
        $this->assertSame('completed', LiprPayment::where('reference_id', 'REF-1')->value('status'));

        // Polled again: nothing happens twice
        $this->as($this->customer)->postJson('/api/v1/lipr-status-service', ['listing_id' => $this->milestones($booking)[0]->id, 'referenceId' => 'REF-1'])->assertOk();
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_a_payment_cannot_be_used_by_someone_else_or_be_too_small(): void
    {
        $booking = $this->acceptedBooking();
        $other = User::factory()->create(['user_type_id' => 1]);
        $first = $this->milestones($booking)[0];
        LiprPayment::create(['reference_id' => 'REF-X', 'transaction_id' => 'TX-X', 'user_id' => $other->id, 'status' => 'successful', 'purpose' => 's_mile', 'amount' => 10500, 'amount_usd' => 10500]);
        $this->as($other)->postJson('/api/v1/lipr-status-service', ['listing_id' => $first->id, 'referenceId' => 'REF-X'])->assertForbidden();

        $this->pay($booking, 'REF-SMALL', 500)->assertStatus(422);
        $this->assertFalse((bool) $booking->refresh()->paid);
    }

    public function test_the_customer_approves_each_done_milestone_in_order_releasing_its_payment(): void
    {
        $booking = $this->acceptedBooking();
        $this->pay($booking)->assertOk();
        [$design, $build] = $this->milestones($booking);

        // Not done yet: nothing to approve
        $this->as($this->customer)->postJson("/api/v1/services/bookings/{$booking->id}/milestones/{$design->id}/approve")->assertStatus(422);
        // The second milestone cannot start before the first is released, and only the provider moves milestones
        $this->move($build, 'In Progress')->assertStatus(422);
        $this->move($design, 'Done', $this->customer)->assertNotFound();

        $this->move($design, 'Done')->assertOk();
        $this->as($this->provider)->postJson("/api/v1/services/bookings/{$booking->id}/milestones/{$design->id}/approve")->assertForbidden();

        $flow = $this->as($this->customer)->postJson("/api/v1/services/bookings/{$booking->id}/milestones/{$design->id}/approve")->assertOk()->json('data');
        $this->assertCount(1, $this->transfers);
        $this->assertSame(4000, $this->transfers[0]['amount']);
        $this->assertSame('WALLET-PROVIDER', $this->transfers[0]['receiver_wallet']);
        $this->assertSame('WALLET-TUJITUME', $this->transfers[0]['payer_wallet']);
        $this->assertTrue($flow['milestones'][0]['released']);
        $this->assertSame('In Progress', $flow['milestones'][1]['status']);
        $this->assertSame('in_progress', $booking->refresh()->status);

        // ISSUE-46: the provider is emailed for this milestone (not only at the end)
        $mails = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage());
        $paid = $mails->first(fn ($e) => str_starts_with((string) $e->getSubject(), 'Milestone approved'));
        $this->assertNotNull($paid);
        $this->assertSame($this->provider->email, $paid->getTo()[0]->getAddress());
        $this->assertStringContainsString('4,000.00', html_entity_decode($paid->getHtmlBody()));
        $this->assertStringContainsString('Build', html_entity_decode($paid->getHtmlBody()));

        // Twice is refused: one release only
        $this->as($this->customer)->postJson("/api/v1/services/bookings/{$booking->id}/milestones/{$design->id}/approve")->assertStatus(409);
        $this->assertCount(1, $this->transfers);

        $this->move($build->refresh(), 'Done')->assertOk();
        $this->as($this->customer)->postJson("/api/v1/services/bookings/{$booking->id}/milestones/{$build->id}/approve")->assertOk()
            ->assertJsonPath('data.state', 'completed');
        $this->assertSame('completed', $booking->refresh()->status);

        // Lipr confirms the release: the provider's balance is credited once
        $this->postJson('/api/lipr-callback/service-milestone?sig='.\App\Service\LiprMpesa\LiprCallback::signature('/lipr-callback/service-milestone'), ['transaction' => ['reference' => 'TR-2', 'id' => 'T2', 'transactionStatus' => 'Successful', 'amount' => 6000], 'metadata' => ['listingId' => $build->id]])->assertOk();
        $this->assertSame('completed', $build->refresh()->release_status);

        // C4: each side sees its own money once: the customer paid 10,500 into escrow (out), the provider got 4,000 + 6,000 (in)
        $mine = fn (User $u) => collect($this->as($u)->getJson('/api/v1/transactions')->assertOk()->json('data'))
            ->map(fn ($t) => [$t['type'], $t['direction'], (float) $t['gross_amount']])->sortBy(2)->values()->all();
        $this->assertSame([['service_fee', 'debit', 10500.0]], $mine($this->customer));
        $this->assertSame([['service_milestone', 'credit', 4000.0], ['service_milestone', 'credit', 6000.0]], $mine($this->provider));
        $stats = $this->as($this->provider)->getJson('/api/v1/transactions?page=1&per_page=10')->assertOk()->json('data.stats');
        $this->assertEquals(10000, collect($stats['by_currency'])->sum('in_total'));
    }

    public function test_requesting_changes_sends_the_milestone_back_and_a_failed_transfer_pays_nothing(): void
    {
        $booking = $this->acceptedBooking();
        $this->pay($booking)->assertOk();
        [$design] = $this->milestones($booking);
        $this->move($design, 'Done')->assertOk();

        $this->as($this->customer)->postJson("/api/v1/services/bookings/{$booking->id}/milestones/{$design->id}/request-changes", ['note' => 'Use our brand colours'])->assertOk();
        $design->refresh();
        $this->assertSame('In Progress', $design->status);
        $this->assertSame('Use our brand colours', $design->change_note);

        $this->move($design, 'Done')->assertOk();
        $this->transferWorks = false;
        $this->as($this->customer)->postJson("/api/v1/services/bookings/{$booking->id}/milestones/{$design->id}/approve")->assertStatus(502);
        $this->assertFalse((bool) $design->refresh()->released);
        $this->assertSame('failed', $design->release_status);
    }

    public function test_old_email_links_never_release_money(): void
    {
        $booking = $this->acceptedBooking();
        $this->pay($booking)->assertOk();
        [$design] = $this->milestones($booking);
        $this->move($design, 'Done')->assertOk();

        $rep = base64_encode($design->id.'.'.($design->id + 51));
        $booker = base64_encode($this->customer->id.'.'.($this->customer->id + 47));
        $this->get("/api/v1/agreeToMileS/{$rep}/{$booker}")->assertRedirect();
        $this->get("/api/v1/reviewMilestoneS/{$rep}/{$booker}")->assertRedirect();

        $this->assertCount(0, $this->transfers);
        $this->assertFalse((bool) $design->refresh()->released);
        $this->assertSame('Done', $design->status);
    }

    public function test_only_the_customer_cancels_and_only_before_paying(): void
    {
        $booking = $this->acceptedBooking();
        $stranger = User::factory()->create(['user_type_id' => 1]);
        $this->as($stranger)->postJson("/api/v1/services/bookings/{$booking->id}/cancel")->assertNotFound();
        $this->as($this->provider)->postJson("/api/v1/services/bookings/{$booking->id}/cancel")->assertForbidden();

        $this->assertTrue($this->as($this->customer)->getJson("/api/v1/services/bookings/{$booking->id}/flow")->json('data.can_cancel'));
        $this->as($this->customer)->postJson("/api/v1/services/bookings/{$booking->id}/cancel")->assertOk();
        // Kept as cancelled, so both sides still see it; it can be booked again
        $this->assertSame('cancelled', $booking->refresh()->status);
        $this->as($this->customer)->postJson("/api/v1/services/bookings/{$booking->id}/cancel")->assertStatus(409);
        $this->book();
        $this->assertSame(2, ServiceBooking::where('booker_id', $this->customer->id)->count());
        $this->assertSame(0, ServiceBookingMilestone::where('booking_id', $booking->id)->count());
    }

    public function test_a_paid_booking_is_not_cancelled_and_old_cancel_links_change_nothing(): void
    {
        $booking = $this->acceptedBooking();
        $this->pay($booking)->assertOk();

        $this->as($this->customer)->postJson("/api/v1/services/bookings/{$booking->id}/cancel")->assertStatus(409);
        $this->assertFalse($this->getJson("/api/v1/services/bookings/{$booking->id}/flow")->json('data.can_cancel'));

        $unpaid = $this->bookAnother();
        foreach ([$booking, $unpaid] as $b) {
            $id = base64_encode((string) $b->id);
            $this->get("/api/v1/CancelServiceBooking/{$id}/ok_response")->assertRedirect();
            $this->get("/api/v1/CancelServiceBooking/{$id}/anything")->assertRedirect();
            $this->get("/api/v1/CancelBookingConfirm/{$id}/ok")->assertRedirect();
            $this->assertNotNull(ServiceBooking::find($b->id));
        }
    }

    public function test_a_rejected_booking_is_kept_with_its_reason_and_the_provider_sees_it_under_all(): void
    {
        $booking = $this->book();
        $this->as($this->provider)->postJson('/api/v1/bookingRejected', ['bid_ids' => [$booking->id], 'reason' => 'Fully booked that week'])->assertOk();
        $booking->refresh();
        $this->assertSame('rejected', $booking->status);
        $this->assertSame('Fully booked that week', $booking->rejection_note);

        $url = fn (string $view) => "/api/v1/services/service_booking?page=1&view={$view}";
        $this->assertSame(0, $this->as($this->provider)->getJson($url('pending'))->json('data.pagination.total'));
        $this->assertSame(0, $this->getJson($url('active'))->json('data.pagination.total'));
        $all = $this->getJson($url('all'))->assertOk()->json('data');
        $this->assertSame('rejected', $all['items'][0]['status']);
        $this->assertNull($all['items'][0]['deadline_at']);
        $this->assertSame(1, $all['stats']['ended']);

        // The customer sees it as rejected, owes nothing, and can book again
        $mine = $this->as($this->customer)->getJson('/api/v1/services/my_booking?page=1')->json('data');
        $this->assertSame('rejected', $mine['items'][0]['status']);
        $this->assertSame(0, $mine['stats']['unpaid_count']);
        $this->book();
    }

    public function test_an_accepted_unpaid_booking_says_exactly_when_it_must_be_paid_by(): void
    {
        $booking = $this->acceptedBooking();
        $row = $this->as($this->customer)->getJson('/api/v1/services/my_booking?page=1')->json('data.items.0');
        // The start of the service date (the API writes dates in the person's own date format)
        $this->assertNotNull($row['deadline_at']);
        $this->assertStringContainsString(\Illuminate\Support\Carbon::parse($booking->date)->format('Y') , $row['deadline_at']);
        $this->assertStringContainsString('00:00', $row['deadline_at']);
        $this->assertSame($row['deadline_at'], $this->getJson("/api/v1/services/bookings/{$booking->id}/flow")->json('data.booking.deadline_at'));
        $this->pay($booking)->assertOk();
        $this->assertNull($this->getJson('/api/v1/services/my_booking?page=1')->json('data.items.0.deadline_at'));
    }

    public function test_the_provider_lists_the_milestones_running_now(): void
    {
        $booking = $this->acceptedBooking();
        $this->as($this->provider)->getJson('/api/v1/services/milestones/running')->assertOk()->assertJsonCount(0, 'data'); // not paid yet
        $this->pay($booking)->assertOk();

        $rows = $this->as($this->provider)->getJson('/api/v1/services/milestones/running')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Design', $rows[0]['title']);
        $this->assertSame('In Progress', $rows[0]['status']);
        $this->assertSame($booking->id, $rows[0]['booking_id']);
        $this->assertFalse($rows[0]['waiting_approval']);

        // Another provider sees none of them
        $this->as(User::factory()->create(['user_type_id' => 3]))->getJson('/api/v1/services/milestones/running');
        $other = User::factory()->create(['user_type_id' => 1]);
        $this->as($other)->getJson('/api/v1/services/milestones/running')->assertOk()->assertJsonCount(0, 'data');
    }

    private function bookAnother(): ServiceBooking
    {
        $other = Services::create(['user_id' => $this->provider->id, 'name' => 'Logo', 'price' => 3000, 'category' => 'Branding', 'location' => 'Nairobi', 'details' => 'A logo', 'active' => 1]);
        $this->as($this->customer)->postJson('/api/v1/serviceBook', ['service_id' => $other->id, 'date' => now()->addDays(4)->toDateString()])->assertOk();

        return ServiceBooking::where('service_id', $other->id)->firstOrFail();
    }

    /** ISSUE-44: an offer works (it was a 500 on every route) and the accepted booking is paid and released by milestone. */
    public function test_an_accepted_offer_books_the_service_at_the_agreed_price_with_its_milestones(): void
    {
        $this->as($this->customer)->postJson("/api/v1/services/{$this->service->id}/offer", ['offered_price' => 8000, 'note' => 'Smaller site'])->assertCreated();
        $this->as($this->customer)->getJson('/api/v1/services/my-offers')->assertOk();
        $offer = \App\Models\Services\ServiceOffer::sole();

        $stranger = User::factory()->create(['user_type_id' => 1]);
        $this->as($stranger)->postJson("/api/v1/services/offers/{$offer->id}/accept")->assertForbidden();
        $this->as($this->provider)->postJson("/api/v1/services/offers/{$offer->id}/accept")->assertOk();

        $booking = ServiceBooking::findOrFail($offer->refresh()->booking_id);
        $this->assertSame('confirmed', $booking->status);
        $milestones = $this->milestones($booking);
        // 4,000 / 6,000 of a 10,000 service, scaled to the 8,000 agreed
        $this->assertSame([3200.0, 4800.0], $milestones->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame(['Design', 'Build'], $milestones->pluck('title')->all());

        $flow = $this->as($this->customer)->getJson("/api/v1/services/bookings/{$booking->id}/flow")->assertOk()->json('data');
        $this->assertTrue($flow['can_pay']);

        // The old flat delivery (it never released money) no longer completes a milestone booking
        $this->as($this->customer)->postJson("/api/v1/services/bookings/{$booking->id}/accept-delivery")->assertStatus(409);
        $this->assertSame('confirmed', $booking->refresh()->status);

        // C2: paid, the provider delivers it milestone by milestone and the customer's approval releases the scaled amount
        $this->pay($booking, 'REF-OFFER', 8400)->assertOk();
        [$design] = $this->milestones($booking);
        $this->move($design->refresh(), 'Done')->assertOk();
        $this->as($this->customer)->postJson("/api/v1/services/bookings/{$booking->id}/milestones/{$design->id}/approve")->assertOk();
        $this->assertSame(3200, end($this->transfers)['amount']);
    }
}
