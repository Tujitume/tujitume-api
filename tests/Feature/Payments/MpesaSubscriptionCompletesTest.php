<?php

namespace Tests\Feature\Payments;

use App\Models\Auth\User;
use App\Models\Business\BusinessSubscriptions;
use App\Service\LiprMpesa\LiprCallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** An M-Pesa subscription payment: LIPR's callback says "Successful", and the subscribe page's poll then starts the plan once. */
class MpesaSubscriptionCompletesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_callback_starts_the_paid_plan_once(): void
    {
        $user = User::factory()->create(['user_type_id' => 1]);
        DB::table('lipr_payments')->insert(['reference_id' => '77001', 'transaction_id' => '', 'user_id' => $user->id, 'purpose' => 'subscription', 'status' => 'pending',
            'amount' => 3900, 'amount_usd' => 29.99, 'created_at' => now(), 'updated_at' => now()]);

        $poll = fn () => $this->actingAs($user, 'sanctum')->postJson('/api/v1/lipr-subscription', ['plan' => 'gold', 'referenceId' => 77001]);

        $poll()->assertOk()->assertJsonPath('status', 'pending');

        $this->postJson('/api/lipr-callback?sig='.LiprCallback::signature('/lipr-callback'),
            ['transaction' => ['reference' => '77001', 'id' => 'TX-1', 'transactionStatus' => 'Successful', 'amount' => 3900]])->assertOk();

        $poll()->assertOk()->assertJsonPath('status', 'processed');
        $poll()->assertOk()->assertJsonPath('status', 'processed'); // polled again: no second plan, no error

        $subs = BusinessSubscriptions::where('investor_id', $user->id)->get();
        $this->assertCount(1, $subs);
        $this->assertSame('gold', $subs[0]->plan);
        $this->assertSame(0, (int) $subs[0]->trial);
        $this->assertSame('completed', DB::table('lipr_payments')->where('reference_id', '77001')->value('status'));
    }

    public function test_a_failed_payment_says_so(): void
    {
        $user = User::factory()->create(['user_type_id' => 1]);
        DB::table('lipr_payments')->insert(['reference_id' => '77002', 'transaction_id' => '', 'user_id' => $user->id, 'purpose' => 'subscription', 'status' => 'Failed',
            'amount' => 3900, 'amount_usd' => 29.99, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/lipr-subscription', ['plan' => 'gold', 'referenceId' => 77002])
            ->assertOk()->assertJsonPath('status', 'failed');
        $this->assertSame(0, BusinessSubscriptions::where('investor_id', $user->id)->count());
    }
}
