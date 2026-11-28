<?php

use App\Models\Capital\CapitalMilestone;
use App\Models\Capital\StartupPitches;
use App\Models\Finance\LiprPayment;
use App\Models\Finance\Transactions;
use App\Models\Misc\Setting;
use App\Service\Capital\CapitalRelease;
use App\Service\LiprMpesa\LiprW2W;
use Stripe\StripeClient;

uses(Tests\Feature\Capital\CapitalTestCase::class);

/** A Stripe client that records charges and transfers (or fails the charge) */
function capitalFakeStripe($test, bool $chargeFails = false): void
{
    $test->stripe = (object) ['charges' => [], 'transfers' => []];
    app()->instance(StripeClient::class, new class($test, $chargeFails) extends StripeClient {
        public function __construct(private $test, private bool $fails) { parent::__construct('sk_test_fake'); }
        public function __get($name)
        {
            $test = $this->test; $fails = $this->fails;
            return new class($test, $name, $fails) {
                public function __construct(private $test, private string $name, private bool $fails) {}
                public function create(array $params, $opts = null)
                {
                    if ($this->name === 'charges' && $this->fails) {
                        throw new \Stripe\Exception\CardException('Card declined');
                    }
                    $this->test->stripe->{$this->name}[] = $params;
                    return (object) ['id' => ($this->name === 'charges' ? 'ch_' : 'tr_').count($this->test->stripe->{$this->name}), 'status' => 'succeeded'];
                }
            };
        }
    });
}

beforeEach(function () {
    [$this->pitch, $this->first, $this->second] = $this->fundablePitch();
    $this->applicantUser->forceFill(['stripe_connect_id' => 'acct_sme', 'lipr_wallet' => 'SME-WALLET'])->save();
});

describe('Releasing capital by card', function () {
    it('charges the provider the amount plus the fee and sends the full amount, recorded once', function () {
        capitalFakeStripe($this);
        $this->asCapital()->postJson('/api/v1/capital/capital-milestone', ['listing' => $this->first->id, 'stripeToken' => 'tok'])->assertOk()
            ->assertJsonPath('amount', 2000)->assertJsonPath('transfer', 'sent');

        $fee = CapitalRelease::fee(2000, 'stripe');
        expect($fee)->toBeGreaterThan(0);
        expect($this->stripe->charges[0]['amount'])->toBe((int) round((2000 + $fee) * 100));
        expect($this->stripe->transfers[0]['amount'])->toBe(200000)->and($this->stripe->transfers[0]['destination'])->toBe('acct_sme');

        $m = $this->first->fresh();
        expect($m->fund_released)->toBeTrue()->and($m->release_status)->toBe('released')->and($m->released_at)->not->toBeNull()->and($m->release_reference)->toBe('ch_1');
        expect((float) $this->offer->fresh()->available_amount)->toEqual(98000.0);
        expect(Transactions::where('type', 'capital_milestone')->count())->toBe(2);
        expect(Transactions::where('user_id', $this->capitalOwner->id)->value('direction'))->toBe('debit')
            ->and((float) Transactions::where('user_id', $this->capitalOwner->id)->value('gross_amount'))->toEqual(round(2000 + $fee, 2))
            // the provider's row says what was fees and what the business received
            ->and((float) Transactions::where('user_id', $this->capitalOwner->id)->value('fee_amount'))->toEqual(round($fee, 2))
            ->and((float) Transactions::where('user_id', $this->capitalOwner->id)->value('net_amount'))->toEqual(2000.0)
            ->and(Transactions::where('user_id', $this->applicantUser->id)->value('direction'))->toBe('credit');
        expect(\App\Models\Communication\Notifications::where('receiver_id', $this->applicantUser->id)->where('link', 'dashboard.entrepreneur.capitalDealroom?pitch=@'.$this->pitch->id.'&tab=funding')->exists())->toBeTrue();

        // already paid
        $this->asCapital()->postJson('/api/v1/capital/capital-milestone', ['listing' => $this->first->id, 'stripeToken' => 'tok'])->assertStatus(409);
    });

    it('releases every remaining milestone with percent 100 and checks the pool against that total', function () {
        capitalFakeStripe($this);
        $this->offer->update(['available_amount' => 4000]);
        $this->asCapital()->postJson('/api/v1/capital/capital-milestone', ['listing' => $this->first->id, 'percent' => 100, 'stripeToken' => 'tok'])
            ->assertUnprocessable()->assertJsonPath('message', 'Insufficient available funds on this capital offer.');
        expect($this->stripe->charges)->toBe([]);

        $this->offer->update(['available_amount' => 5000]);
        $this->asCapital()->postJson('/api/v1/capital/capital-milestone', ['listing' => $this->first->id, 'percent' => 100, 'stripeToken' => 'tok'])->assertOk()->assertJsonPath('amount', 5000);
        expect(CapitalMilestone::where('app_id', $this->pitch->id)->where('fund_released', true)->count())->toBe(2);
        expect((float) $this->offer->fresh()->available_amount)->toEqual(0.0);
    });

    it('leaves nothing released when the charge fails', function () {
        capitalFakeStripe($this, chargeFails: true);
        $this->asCapital()->postJson('/api/v1/capital/capital-milestone', ['listing' => $this->first->id, 'stripeToken' => 'tok'])->assertStatus(400);
        $m = $this->first->fresh();
        expect($m->fund_released)->toBeFalse()->and($m->release_status)->toBe('failed')->and((int) $m->status)->toBe(0);
        expect((float) $this->offer->fresh()->available_amount)->toEqual(100000.0);
    });

    it('needs accepted terms, the right order and the owner or a super admin', function () {
        capitalFakeStripe($this);
        $this->asCapital()->postJson('/api/v1/capital/capital-milestone', ['listing' => $this->second->id, 'stripeToken' => 'tok'])->assertUnprocessable()->assertJsonPath('message', 'Earlier milestones must be released first.');
        $this->asCapital($this->teamMember($this->capitalOwner, 'editor'))->postJson('/api/v1/capital/capital-milestone', ['listing' => $this->first->id, 'stripeToken' => 'tok'])->assertForbidden();
        $this->asCapital($this->makeCapitalOwner())->postJson('/api/v1/capital/capital-milestone', ['listing' => $this->first->id, 'stripeToken' => 'tok'])->assertNotFound();

        $this->pitch->latestAgreement()->first()->update(['status' => 'rejected']);
        $this->asCapital()->postJson('/api/v1/capital/capital-milestone', ['listing' => $this->first->id, 'stripeToken' => 'tok'])->assertUnprocessable()->assertJsonPath('message', 'The terms agreement must be accepted before funds are released.');
        expect($this->stripe->charges)->toBe([]);

        // a capital super admin may pay
        $this->pitch->latestAgreement()->first()->update(['status' => 'accepted']);
        $this->asCapital($this->teamMember($this->capitalOwner, 'super_admin'))->postJson('/api/v1/capital/capital-milestone', ['listing' => $this->first->id, 'stripeToken' => 'tok'])->assertOk();
    });
});

describe('Releasing capital by M-Pesa', function () {
    beforeEach(function () {
        Setting::create(['key' => 'platform_lipr_wallet', 'value' => 'TUJ-WALLET']);
        $this->sent = [];
        $test = $this;
        $this->app->instance(LiprW2W::class, new class($test) extends LiprW2W {
            public bool $fail = false;
            public function __construct(private $test) {}
            public function send($amount, string $receiver_wallet, string $payer_wallet, $milestone = null, array $options = [])
            {
                if ($this->test->failTransfer ?? false) {
                    return ['success' => false, 'errors' => ['Wallet offline']];
                }
                $this->test->sent[] = compact('amount', 'receiver_wallet', 'payer_wallet');
                return ['success' => true];
            }
        });
        // Covers the 2,000 milestone plus the M-Pesa fee on top
        $this->pay = fn (string $status = 'successful', array $over = []) => LiprPayment::create(array_merge(['reference_id' => 'REF-'.uniqid(), 'transaction_id' => 'T-'.uniqid(), 'user_id' => $this->capitalOwner->id, 'status' => $status, 'purpose' => 'capital_milestone', 'amount' => 2000 + CapitalRelease::fee(2000, 'mpesa'), 'amount_usd' => 16], $over));
    });

    it('sends the full milestone amount once the payment is in, recorded once', function () {
        $payment = ($this->pay)();
        $this->asCapital()->postJson('/api/v1/lipr-capital-disbursement-status', ['milestone_id' => $this->first->id, 'referenceId' => $payment->reference_id])->assertOk()->assertJsonPath('status', 'completed');
        expect($this->sent)->toHaveCount(1)->and((float) $this->sent[0]['amount'])->toEqual(2000.0)->and($this->sent[0]['receiver_wallet'])->toBe('SME-WALLET');
        expect($this->first->fresh()->fund_released)->toBeTrue()->and($payment->fresh()->status)->toBe('completed');

        // polling again does not pay twice
        $this->asCapital()->postJson('/api/v1/lipr-capital-disbursement-status', ['milestone_id' => $this->first->id, 'referenceId' => $payment->reference_id])->assertOk()->assertJsonPath('status', 'completed');
        expect($this->sent)->toHaveCount(1);
    });

    it('puts the release back when the transfer fails, and waits for a pending payment', function () {
        $pending = ($this->pay)('pending');
        $this->asCapital()->postJson('/api/v1/lipr-capital-disbursement-status', ['milestone_id' => $this->first->id, 'referenceId' => $pending->reference_id])->assertOk()->assertJsonPath('status', 'pending');

        $this->failTransfer = true;
        $payment = ($this->pay)();
        $this->asCapital()->postJson('/api/v1/lipr-capital-disbursement-status', ['milestone_id' => $this->first->id, 'referenceId' => $payment->reference_id])->assertUnprocessable()->assertJsonPath('message', 'Wallet offline');
        expect($this->first->fresh()->fund_released)->toBeFalse()->and($this->first->fresh()->release_status)->toBe('failed');

        $this->failTransfer = false;
        $this->asCapital()->postJson('/api/v1/lipr-capital-disbursement-status', ['milestone_id' => $this->first->id, 'referenceId' => $payment->reference_id])->assertOk()->assertJsonPath('status', 'completed');
    });

    it('releases nothing for a payment that is too small, made for something else, or made by someone else', function () {
        $poll = fn ($payment) => $this->asCapital()->postJson('/api/v1/lipr-capital-disbursement-status', ['milestone_id' => $this->first->id, 'referenceId' => $payment->reference_id]);

        $poll(($this->pay)('successful', ['amount' => 50]))->assertUnprocessable();
        $poll(($this->pay)('successful', ['purpose' => 'small_fee']))->assertUnprocessable();
        $poll(($this->pay)('successful', ['user_id' => $this->applicantUser->id]))->assertForbidden();

        expect($this->sent)->toBeEmpty()->and($this->first->fresh()->fund_released)->toBeFalse();
    });

    it('works out the M-Pesa fee on top for the provider', function () {
        expect(CapitalRelease::fee(2000, 'mpesa'))->toBeGreaterThan(0);
    });
});
