<?php

use App\Models\Programs\Disbursement;
use App\Models\Programs\ProgramMilestone;
use App\Service\LiprMpesa\LiprCallback;
use App\Service\LiprMpesa\LiprW2W;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// A payout straight to the applicant used to report to /lipr-callback-program-supplier, which has no route, so the
// milestone stayed "disbursing" for ever. It now reports to the program payout callback.

beforeEach(function () {
    $this->ms = ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => 1, 'is_template' => false,
        'amount' => 5000, 'status' => 'disbursing', 'fund_release_status' => 'processing', 'fund_released' => false]);
    $this->disbursement = Disbursement::create(['milestone_id' => $this->ms->id, 'recipient_type' => 'business_owner', 'amount' => 5000,
        'currency' => 'KES', 'payment_method' => 'mpesa_mobile', 'status' => 'processing', 'payment_reference' => 'TR-9']);
    $this->callback = fn (string $status) => $this->postJson('/api/lipr-callback/transfer-to-supplier?sig='.LiprCallback::signature('/lipr-callback/transfer-to-supplier'),
        ['transaction' => ['reference' => 'TR-9', 'id' => 'T9', 'transactionStatus' => $status, 'amount' => 5000], 'metadata' => ['listingId' => $this->ms->id]]);
});

it('sends program milestone payouts to the program payout callback, other transfers to the plain one', function () {
    expect(LiprW2W::resultUrl($this->ms))->toContain('/lipr-callback/transfer-to-supplier?sig=')
        ->and(LiprW2W::resultUrl('Unlocking business fee'))->toContain('/lipr-callback/transfer?sig=')
        ->and(LiprW2W::resultUrl(null, ['result_url' => 'https://x.test/cb']))->toBe('https://x.test/cb');
});

it('releases a direct payout to the applicant when LIPR confirms it', function () {
    ($this->callback)('Successful')->assertOk();
    expect($this->ms->fresh()->fund_release_status)->toBe('released')
        ->and((bool) $this->ms->fresh()->fund_released)->toBeTrue()
        ->and($this->disbursement->fresh()->status)->toBe('completed');
});

it('takes back the applicant credit once when the payout failed, and lets it be released again', function () {
    (new \App\Service\Balance\BalanceService())->updateBalance($this->applicantUser->id, 5000, 'lipr'); // credited when sent
    ($this->callback)('Failed')->assertOk();
    ($this->callback)('Failed')->assertOk(); // a repeated callback changes nothing more
    expect($this->ms->fresh()->fund_release_status)->toBe('approved')
        ->and($this->disbursement->fresh()->status)->toBe('failed')
        ->and((float) $this->applicantUser->fresh()->balance->balance)->toBe(0.0);
});

it('records a plain transfer result', function () {
    $this->postJson('/api/lipr-callback/transfer?sig='.LiprCallback::signature('/lipr-callback/transfer'),
        ['transaction' => ['reference' => 'UNL-1', 'id' => 'U1', 'transactionStatus' => 'Successful', 'amount' => 300], 'metadata' => []])->assertOk();
    expect(\App\Models\Finance\LiprPayment::where('reference_id', 'UNL-1')->exists())->toBeTrue();
});
