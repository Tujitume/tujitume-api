<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Misc\Setting;
use App\Service\LiprMpesa\LiprW2W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The unlock fee (Listing::investors_fee) is a fixed KSh amount: the poll checks it against the KSh really paid and
 * sends the owner that KSh amount. It used to compare it with the payment's USD value (KSh 1,386 paid = USD 10.66 <
 * 1,300, so every unlock was refused) and to send the owner the fee times the USD rate.
 */
class UnlockFeeInKesTest extends TestCase
{
    use RefreshDatabase;

    public array $transfers = [];

    private function setUpUnlock(float $paidKes): array
    {
        config(['mail.default' => 'array']);
        $test = $this;
        $this->app->instance(LiprW2W::class, new class($test) extends LiprW2W {
            public function __construct(private $test) {}

            public function send($amount, string $receiver_wallet, string $payer_wallet, $milestone = null, array $options = [])
            {
                $this->test->transfers[] = compact('amount', 'receiver_wallet');

                return ['success' => true];
            }
        });
        Setting::updateOrCreate(['key' => 'platform_lipr_wallet'], ['value' => 'PLATFORM-WALLET']);
        $owner = User::factory()->create(['user_type_id' => 1, 'lipr_wallet' => 'OWNER-WALLET']);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Solar lanterns', 'category' => 'Energy', 'location' => 'Nairobi',
            'investment_needed' => 400000, 'share' => 20, 'investors_fee' => 1300, 'active' => 1]);
        DB::table('lipr_payments')->insert(['reference_id' => 'UNLOCK-1', 'transaction_id' => 'TX-U1', 'user_id' => $investor->id, 'status' => 'successful',
            'purpose' => 'small_fee', 'amount' => $paidKes, 'amount_usd' => round($paidKes / 130, 2), 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $investor, $listing];
    }

    public function test_the_ksh_fee_paid_unlocks_the_business_and_the_owner_gets_ksh(): void
    {
        [, $investor, $listing] = $this->setUpUnlock(1386); // 1,300 + platform and M-Pesa fees

        $this->actingAs($investor)->postJson('/api/v1/lipr-status-smallFee', ['business_id' => $listing->id, 'referenceId' => 'UNLOCK-1'])->assertOk();

        $this->assertTrue(DB::table('conversations')->where('investor_id', $investor->id)->where('listing_id', $listing->id)->exists());
        $this->assertSame('completed', DB::table('lipr_payments')->where('reference_id', 'UNLOCK-1')->value('status'));
        $this->assertCount(1, $this->transfers);
        $this->assertEquals(1300, $this->transfers[0]['amount']);
        $this->assertSame('OWNER-WALLET', $this->transfers[0]['receiver_wallet']);
    }

    public function test_a_payment_lipr_has_not_confirmed_yet_is_pending_not_an_error(): void
    {
        [, $investor, $listing] = $this->setUpUnlock(1386);
        DB::table('lipr_payments')->where('reference_id', 'UNLOCK-1')->update(['status' => 'pending']);

        $this->actingAs($investor)->postJson('/api/v1/lipr-status-smallFee', ['business_id' => $listing->id, 'referenceId' => 'UNLOCK-1'])
            ->assertOk()->assertJsonPath('status', 'pending');
        $this->actingAs($investor)->postJson('/api/v1/lipr-status-bids', ['business_id' => $listing->id, 'referenceId' => 'UNLOCK-1', 'amountUSD' => 1000, 'amountPaid' => 1386])
            ->assertOk()->assertJsonPath('status', 'pending');
        $this->assertFalse(DB::table('conversations')->where('listing_id', $listing->id)->exists());
    }

    public function test_less_than_the_fee_is_refused(): void
    {
        [, $investor, $listing] = $this->setUpUnlock(500);

        $this->actingAs($investor)->postJson('/api/v1/lipr-status-smallFee', ['business_id' => $listing->id, 'referenceId' => 'UNLOCK-1'])->assertStatus(422);

        $this->assertFalse(DB::table('conversations')->where('listing_id', $listing->id)->exists());
        $this->assertSame([], $this->transfers);
    }
}
