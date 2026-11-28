<?php

use App\Http\Controllers\CheckoutMpesaController;
use App\Models\Programs\ProgramMilestone;
use Illuminate\Http\Request;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// Funding a program's escrow by M-Pesa charges the whole award when nothing is released yet, and only the unreleased
// milestones once some are already released (for example imported historic payments). The amount must reach the STK
// push: it used to be dropped (escrow was missing from the purposes charged from $amount), so 0 was asked for.

function escrowCharge($test, ProgramMilestone $milestone): float
{
    $test->actingAs($test->orgUser);
    $controller = app(CheckoutMpesaController::class);
    $request = Request::create('/', 'POST', ['purpose' => 'program_disbursement_escrow', 'listing_id' => $milestone->id]);
    $resolve = (new ReflectionMethod($controller, 'resolveInitiatePaymentDetails'));
    $payment = $resolve->invoke($controller, $request, 'https://example.test/cb');
    $calculate = (new ReflectionMethod($controller, 'calculateInitiatePaymentAmount'));

    return (float) $calculate->invoke($controller, 'program_disbursement_escrow', $payment['amount'], $payment['amountKes']);
}

beforeEach(function () {
    $this->application->update(['total_amount_requested' => 30000]);
    $this->m1 = ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => 1, 'is_template' => false, 'amount' => 10000, 'fund_release_status' => 'locked']);
    $this->m2 = ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => 2, 'is_template' => false, 'amount' => 20000, 'fund_release_status' => 'locked']);
});

it('charges the whole award when no milestone is released', function () {
    expect(escrowCharge($this, $this->m1))->toBe(30000.0);
});

it('charges only the unreleased milestones once some are released', function () {
    $this->m1->update(['fund_release_status' => 'released']);
    expect(escrowCharge($this, $this->m2))->toBe(20000.0);
});

it('funds the escrow when LIPR confirms a payment the checkout recorded as pending', function () {
    \Illuminate\Support\Facades\Notification::fake();
    $this->m1->update(['fund_release_status' => 'approved']);
    \App\Models\Finance\LiprPayment::create(['reference_id' => 'ESC-1', 'transaction_id' => 'PENDING-ESC-1', 'user_id' => $this->orgUser->id, 'status' => 'pending', 'purpose' => 'program_disbursement_escrow', 'amount' => 30000, 'amount_usd' => 230]);
    $path = '/lipr-callback/disbursement-escrow';
    $body = ['transaction' => ['reference' => 'ESC-1', 'id' => 'T-1', 'transactionStatus' => 'Successful', 'amount' => 30000], 'metadata' => ['listingId' => $this->m1->id]];

    $this->postJson('/api'.$path.'?sig='.\App\Service\LiprMpesa\LiprCallback::signature($path), $body)->assertOk()->assertJson(['message' => 'Callback received']);
    expect((bool) $this->application->fresh()->escrow_funded)->toBeTrue()
        ->and(strtolower(\App\Models\Finance\LiprPayment::where('reference_id', 'ESC-1')->value('status')))->toBe('successful');

    // The same callback again is a repeat
    $this->postJson('/api'.$path.'?sig='.\App\Service\LiprMpesa\LiprCallback::signature($path), $body)->assertOk()->assertJson(['message' => 'Callback already received']);
});
