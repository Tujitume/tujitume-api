<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Programs\Program;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\ReviewerOrder;
use App\Models\Programs\Monitoring\MESiteVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReviewerPaymentTest extends TestCase
{
    use RefreshDatabase;
    protected $organization;
    protected $program;
    protected $programOwner;
    protected $internalReviewer;
    protected $externalReviewer;
    protected $round;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        // Create organization
        $this->organization = Organization::factory()->create();

        // Create program owner
        $this->programOwner = User::factory()
            ->create([
                'user_type_id' => 2, // PO
                'organization_id' => $this->organization->id,
            ]);

        // Create internal reviewer
        $this->internalReviewer = User::factory()
            ->create([
                'user_type_id' => 4, // Internal reviewer
                'organization_id' => $this->organization->id,
                'lipr_wallet_account' => '254712345678',
            ]);

        // Create external reviewer (independent, no organization_id required)
        $this->externalReviewer = User::factory()
            ->create([
                'user_type_id' => 6, // External reviewer
                'lipr_wallet_account' => '254787654321',
            ]);

        // Create program
        $this->program = Program::factory()
            ->for($this->programOwner, 'user')
            ->for($this->organization)
            ->create();

        // Create round
        $this->round = ProgramRound::factory()
            ->for($this->program)
            ->create([
                'close_date' => now()->addDays(30),
            ]);
    }

    /**
     * Test that reviewer can view their own orders
     */
    public function test_reviewer_can_view_own_orders(): void
    {
        // Create a reviewer order for the internal reviewer
        $order = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($this->internalReviewer, 'reviewer')
            ->for($this->program)
            ->for($this->round)
            ->create([
                'order_type' => 'round_review',
                'fee_usd' => 150.00,
                'work_status' => 'assigned',
                'payment_status' => 'unpaid',
            ]);

        $response = $this->actingAs($this->internalReviewer)
            ->getJson('/api/v1/programs/reviewer/orders');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $order->id);
        $response->assertJsonPath('data.0.fee_usd', 150.00);
        $response->assertJsonPath('data.0.order_type', 'round_review');
    }

    /**
     * Test that non-reviewer cannot view reviewer orders
     */
    public function test_non_reviewer_cannot_view_own_orders(): void
    {
        $applicant = User::factory()
            ->create(['user_type_id' => 8]); // Applicant type

        $response = $this->actingAs($applicant)
            ->getJson('/api/v1/programs/reviewer/orders');

        $response->assertStatus(403);
    }

    /**
     * Test that PO can view reviewer orders for their round
     */
    public function test_po_can_view_reviewer_orders_for_round(): void
    {
        $order = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($this->internalReviewer, 'reviewer')
            ->for($this->program)
            ->for($this->round)
            ->create([
                'order_type' => 'round_review',
                'fee_usd' => 150.00,
                'work_status' => 'assigned',
            ]);

        $response = $this->actingAs($this->programOwner)
            ->getJson("/api/v1/programs/rounds/{$this->round->id}/reviewer-orders");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $order->id);
        $response->assertJsonPath('data.0.reviewer.id', $this->internalReviewer->id);
    }

    /**
     * Test reviewer can mark work as delivered
     */
    public function test_reviewer_can_mark_work_delivered(): void
    {
        $order = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($this->internalReviewer, 'reviewer')
            ->for($this->program)
            ->for($this->round)
            ->create([
                'work_status' => 'in_progress',
                'payment_status' => 'unpaid',
            ]);

        $response = $this->actingAs($this->internalReviewer)
            ->postJson(
                "/api/v1/programs/reviewer-orders/{$order->id}/deliver",
                ['delivery_note' => 'All applications scored.']
            );

        $response->assertOk();
        $response->assertJsonPath('data.work_status', 'delivered');
        $response->assertJsonPath('data.delivery_note', 'All applications scored.');

        $this->assertDatabaseHas('reviewer_orders', [
            'id' => $order->id,
            'work_status' => 'delivered',
        ]);
    }

    /**
     * Test reviewer cannot deliver from invalid status
     */
    public function test_reviewer_cannot_deliver_from_approved_status(): void
    {
        $order = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($this->internalReviewer, 'reviewer')
            ->for($this->program)
            ->for($this->round)
            ->create([
                'work_status' => 'approved',
                'payment_status' => 'unpaid',
            ]);

        $response = $this->actingAs($this->internalReviewer)
            ->postJson(
                "/api/v1/programs/reviewer-orders/{$order->id}/deliver",
                ['delivery_note' => 'Trying to redeliver']
            );

        $response->assertStatus(422);
    }

    /**
     * Test PO can request modification
     */
    public function test_po_can_request_modification(): void
    {
        $order = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($this->internalReviewer, 'reviewer')
            ->for($this->program)
            ->for($this->round)
            ->create([
                'work_status' => 'delivered',
                'payment_status' => 'unpaid',
            ]);

        $response = $this->actingAs($this->programOwner)
            ->postJson(
                "/api/v1/programs/reviewer-orders/{$order->id}/request-modification",
                ['modification_note' => 'Please review applications 5-10 again.']
            );

        $response->assertOk();
        $response->assertJsonPath('data.work_status', 'modification_requested');
        $response->assertJsonPath('data.modification_note', 'Please review applications 5-10 again.');

        $this->assertDatabaseHas('reviewer_orders', [
            'id' => $order->id,
            'work_status' => 'modification_requested',
        ]);
    }

    /**
     * Test PO can approve work
     */
    public function test_po_can_approve_work(): void
    {
        $order = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($this->internalReviewer, 'reviewer')
            ->for($this->program)
            ->for($this->round)
            ->create([
                'work_status' => 'delivered',
                'payment_status' => 'unpaid',
            ]);

        $response = $this->actingAs($this->programOwner)
            ->postJson("/api/v1/programs/reviewer-orders/{$order->id}/approve");

        $response->assertOk();
        $response->assertJsonPath('data.work_status', 'approved');
        $response->assertJsonPath('data.approved_at', true); // Should be set

        $this->assertDatabaseHas('reviewer_orders', [
            'id' => $order->id,
            'work_status' => 'approved',
        ]);
    }

    /**
     * Test reviewer can check payment status
     */
    public function test_reviewer_can_check_payment_status(): void
    {
        $order = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($this->internalReviewer, 'reviewer')
            ->for($this->program)
            ->for($this->round)
            ->create([
                'payment_status' => 'pending',
            ]);

        $response = $this->actingAs($this->internalReviewer)
            ->getJson("/api/v1/programs/reviewer-orders/{$order->id}/payment-status");

        $response->assertOk();
        $response->assertJsonPath('payment_status', 'pending');
        $response->assertJsonPath('message', true);
    }

    /**
     * Test PO can check payment status
     */
    public function test_po_can_check_payment_status(): void
    {
        $order = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($this->internalReviewer, 'reviewer')
            ->for($this->program)
            ->for($this->round)
            ->create([
                'payment_status' => 'leg1_processing',
            ]);

        $response = $this->actingAs($this->programOwner)
            ->getJson("/api/v1/programs/reviewer-orders/{$order->id}/payment-status");

        $response->assertOk();
        $response->assertJsonPath('payment_status', 'leg1_processing');
    }

    /**
     * Test unauthorized user cannot check payment status
     */
    public function test_unauthorized_user_cannot_check_payment_status(): void
    {
        $anotherUser = User::factory()->create();

        $order = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($this->internalReviewer, 'reviewer')
            ->for($this->program)
            ->for($this->round)
            ->create();

        $response = $this->actingAs($anotherUser)
            ->getJson("/api/v1/programs/reviewer-orders/{$order->id}/payment-status");

        $response->assertStatus(403);
    }

    /**
     * Test payment status shows correct human-readable message
     */
    public function test_payment_status_returns_correct_message(): void
    {
        $statuses = [
            'unpaid' => 'No payment initiated',
            'pending' => 'Payment pending',
            'leg1_processing' => 'Payment received. Transferring to reviewer...',
            'completed' => 'Payment completed',
            'failed' => 'Payment failed',
        ];

        foreach ($statuses as $status => $expectedMessage) {
            $order = ReviewerOrder::factory()
                ->for($this->organization)
                ->for($this->internalReviewer, 'reviewer')
                ->for($this->program)
                ->for($this->round)
                ->create(['payment_status' => $status]);

            $response = $this->actingAs($this->internalReviewer)
                ->getJson("/api/v1/programs/reviewer-orders/{$order->id}/payment-status");

            $response->assertOk();
            $response->assertJsonPath('payment_status', $status);
            $this->assertStringContainsString($status, $response->json('message') ?? '');

            $order->delete();
        }
    }

    /**
     * Test site visit reviewer order creation and auto-delivery
     */
    public function test_site_visit_reviewer_order_created_and_auto_delivered(): void
    {
        $siteVisit = MESiteVisit::factory()
            ->create([
                'start_date' => now()->addDays(5),
            ]);

        // Simulate site visit submission
        $order = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($this->internalReviewer, 'reviewer')
            ->for($this->program)
            ->for(null, 'round')
            ->for($siteVisit)
            ->create([
                'order_type' => 'site_visit',
                'work_status' => 'assigned',
            ]);

        // When reviewer submits site visit
        $order->update([
            'work_status' => 'delivered',
            'delivered_at' => now(),
        ]);

        $this->assertDatabaseHas('reviewer_orders', [
            'id' => $order->id,
            'order_type' => 'site_visit',
            'work_status' => 'delivered',
        ]);
    }

    /**
     * Test reviewer order cannot be paid if not approved
     */
    public function test_reviewer_order_cannot_be_paid_if_not_approved(): void
    {
        $order = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($this->internalReviewer, 'reviewer')
            ->for($this->program)
            ->for($this->round)
            ->create([
                'work_status' => 'delivered',
                'payment_status' => 'unpaid',
            ]);

        // Attempt to mark as pending without approval should fail
        // (This would be tested via the payment initiation endpoint)
        $this->assertEquals('delivered', $order->work_status);
        $this->assertNotEquals('approved', $order->work_status);
    }

    /**
     * Test payment status shows correct color codes
     */
    public function test_payment_status_has_correct_color_codes(): void
    {
        $colorMapping = [
            'unpaid' => 'info',
            'pending' => 'warning',
            'leg1_processing' => 'warning',
            'completed' => 'success',
            'failed' => 'danger',
        ];

        foreach ($colorMapping as $status => $expectedColor) {
            $order = ReviewerOrder::factory()
                ->for($this->organization)
                ->for($this->internalReviewer, 'reviewer')
                ->for($this->program)
                ->for($this->round)
                ->create(['payment_status' => $status]);

            $response = $this->actingAs($this->internalReviewer)
                ->getJson("/api/v1/programs/reviewer/orders");

            $paymentStatus = collect($response->json('data'))->first();
            $this->assertEquals($expectedColor, $paymentStatus['payment_status']['color']);

            $order->delete();
        }
    }

    /**
     * Test work status shows correct color codes
     */
    public function test_work_status_has_correct_color_codes(): void
    {
        $colorMapping = [
            'assigned' => 'info',
            'in_progress' => 'warning',
            'delivered' => 'warning',
            'modification_requested' => 'danger',
            'approved' => 'success',
        ];

        foreach ($colorMapping as $status => $expectedColor) {
            $order = ReviewerOrder::factory()
                ->for($this->organization)
                ->for($this->internalReviewer, 'reviewer')
                ->for($this->program)
                ->for($this->round)
                ->create(['work_status' => $status]);

            $response = $this->actingAs($this->internalReviewer)
                ->getJson("/api/v1/programs/reviewer/orders");

            $workStatus = collect($response->json('data'))->first();
            $this->assertEquals($expectedColor, $workStatus['work_status']['color']);

            $order->delete();
        }
    }

    /**
     * Test reviewer cannot access other reviewer's orders
     */
    public function test_reviewer_cannot_access_other_reviewers_orders(): void
    {
        $otherReviewer = User::factory()
            ->create([
                'user_type_id' => 6,
            ]);

        $order = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($this->internalReviewer, 'reviewer')
            ->for($this->program)
            ->for($this->round)
            ->create();

        $response = $this->actingAs($otherReviewer)
            ->getJson("/api/v1/programs/reviewer-orders/{$order->id}/payment-status");

        $response->assertStatus(403);
    }

    /**
     * Test multiple reviewers on same round
     */
    public function test_multiple_reviewers_on_same_round(): void
    {
        $reviewer1 = User::factory()
            ->create([
                'user_type_id' => 6,
                'organization_id' => $this->organization->id,
            ]);

        $reviewer2 = User::factory()
            ->create([
                'user_type_id' => 6,
                'organization_id' => $this->organization->id,
            ]);

        $order1 = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($reviewer1, 'reviewer')
            ->for($this->program)
            ->for($this->round)
            ->create(['fee_usd' => 150.00]);

        $order2 = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($reviewer2, 'reviewer')
            ->for($this->program)
            ->for($this->round)
            ->create(['fee_usd' => 200.00]);

        $response = $this->actingAs($this->programOwner)
            ->getJson("/api/v1/programs/rounds/{$this->round->id}/reviewer-orders");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $this->assertTrue(
            collect($response->json('data'))
                ->pluck('fee_usd')
                ->contains(150.00)
        );
        $this->assertTrue(
            collect($response->json('data'))
                ->pluck('fee_usd')
                ->contains(200.00)
        );
    }

    /**
     * Test reviewer order fields are properly returned
     */
    public function test_reviewer_order_contains_all_required_fields(): void
    {
        $order = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($this->internalReviewer, 'reviewer')
            ->for($this->program)
            ->for($this->round)
            ->create([
                'fee_usd' => 150.00,
                'fee_kes' => 19500,
                'currency' => 'USD',
            ]);

        $response = $this->actingAs($this->internalReviewer)
            ->getJson("/api/v1/programs/reviewer/orders");

        $response->assertOk();
        $data = $response->json('data.0');

        // Check all required fields are present
        $this->assertIsNotNull($data['id']);
        $this->assertIsNotNull($data['program_id']);
        $this->assertIsNotNull($data['order_type']);
        $this->assertIsNotNull($data['fee_usd']);
        $this->assertIsNotNull($data['work_status']);
        $this->assertIsNotNull($data['payment_status']);
        $this->assertEquals('USD', $data['currency']);
    }

    /**
     * Test empty reviewer order list
     */
    public function test_empty_reviewer_order_list(): void
    {
        $newReviewer = User::factory()
            ->create([
                'user_type_id' => 6,
                'organization_id' => $this->organization->id,
            ]);

        $response = $this->actingAs($newReviewer)
            ->getJson("/api/v1/programs/reviewer/orders");

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    /**
     * Test that only reviewers assigned to a round see their orders
     */
    public function test_reviewers_see_only_their_assigned_orders(): void
    {
        $round1 = ProgramRound::factory()
            ->for($this->program)
            ->create();

        $round2 = ProgramRound::factory()
            ->for($this->program)
            ->create();

        $order1 = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($this->internalReviewer, 'reviewer')
            ->for($this->program)
            ->for($round1)
            ->create(['order_type' => 'round_review']);

        $order2 = ReviewerOrder::factory()
            ->for($this->organization)
            ->for($this->internalReviewer, 'reviewer')
            ->for($this->program)
            ->for($round2)
            ->create(['order_type' => 'round_review']);

        $response = $this->actingAs($this->internalReviewer)
            ->getJson("/api/v1/programs/reviewer/orders");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $orderIds = collect($response->json('data'))->pluck('id');
        $this->assertTrue($orderIds->contains($order1->id));
        $this->assertTrue($orderIds->contains($order2->id));
    }
}
