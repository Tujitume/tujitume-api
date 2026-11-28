<?php

namespace Tests\Feature\Payments;

use App\Models\Auth\User;
use App\Service\LiprMpesa\LiprCallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** LIPR's payment callback updates the row the checkout started (who paid, for what) instead of creating a nameless one. */
class LiprCallbackKeepsPayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_callback_keeps_who_started_the_payment(): void
    {
        $investor = User::factory()->create(['user_type_id' => 2]);
        DB::table('lipr_payments')->insert(['reference_id' => 'LIPR-REF-9', 'transaction_id' => '', 'user_id' => $investor->id, 'purpose' => 'bids', 'status' => 'pending',
            'amount' => 1300000, 'amount_usd' => 10000, 'created_at' => now(), 'updated_at' => now()]);

        $sig = LiprCallback::signature('/lipr-callback');
        $this->postJson('/api/lipr-callback?sig='.$sig, ['transaction' => ['reference' => 'LIPR-REF-9', 'id' => 'TX-9', 'transactionStatus' => 'Successful', 'amount' => 1300000]])
            ->assertOk();

        $rows = DB::table('lipr_payments')->where('reference_id', 'LIPR-REF-9')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($investor->id, (int) $rows[0]->user_id);
        $this->assertSame('bids', $rows[0]->purpose);
        $this->assertSame('Successful', $rows[0]->status);
        $this->assertSame('TX-9', $rows[0]->transaction_id);
    }

    public function test_a_repeated_callback_does_not_make_a_used_payment_usable_again(): void
    {
        // a capital release (or a deposit) marks its payment completed once used; LIPR resending the callback used to
        // put it back to Successful, so the same payment could release another milestone
        DB::table('lipr_payments')->insert(['reference_id' => 'USED-1', 'transaction_id' => 'TX-U1', 'user_id' => 1, 'purpose' => 'capital_milestone', 'status' => 'completed',
            'amount' => 21300, 'amount_usd' => 163, 'created_at' => now(), 'updated_at' => now()]);

        $sig = LiprCallback::signature('/lipr-callback');
        $this->postJson('/api/lipr-callback?sig='.$sig, ['transaction' => ['reference' => 'USED-1', 'id' => 'TX-U1', 'transactionStatus' => 'Successful', 'amount' => 21300]])->assertOk();

        $this->assertSame('completed', DB::table('lipr_payments')->where('reference_id', 'USED-1')->value('status'));
    }

    public function test_two_payments_can_be_pending_at_the_same_time(): void
    {
        // transaction_id is unique: the checkout used to record every pending payment with '' so a second one (anyone's)
        // was never recorded, and its poll then refused the payment
        $investor = User::factory()->create(['user_type_id' => 2]);
        $this->actingAs($investor);
        $checkout = app(\App\Http\Controllers\CheckoutMpesaController::class);
        $remember = new \ReflectionMethod($checkout, 'rememberPaymentIntent');
        $converter = new class extends \App\Service\Balance\CurrencyConverter { public function __construct() {} public function KesToUsd() { return 1 / 130; } };

        $remember->invoke($checkout, ['status' => 200, 'data' => [['reference' => 'REF-A', 'amount' => 1386]]], 'small_fee', 1386, $converter);
        $remember->invoke($checkout, ['status' => 200, 'data' => [['reference' => 'REF-B', 'amount' => 79949]]], 'bids', 79949, $converter);

        $rows = DB::table('lipr_payments')->whereIn('reference_id', ['REF-A', 'REF-B'])->orderBy('reference_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['small_fee', 'bids'], $rows->pluck('purpose')->all());
        $this->assertSame(['pending', 'pending'], $rows->pluck('status')->all());

        // LIPR's callback then puts its own transaction id in
        $sig = LiprCallback::signature('/lipr-callback');
        $this->postJson('/api/lipr-callback?sig='.$sig, ['transaction' => ['reference' => 'REF-B', 'id' => 'TX-B', 'transactionStatus' => 'Successful', 'amount' => 79949]])->assertOk();
        $this->assertSame('TX-B', DB::table('lipr_payments')->where('reference_id', 'REF-B')->value('transaction_id'));
    }
}
