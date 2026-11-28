<?php

namespace Tests\Feature\Organizations;

use App\Models\Auth\User;
use App\Models\Business\BusinessSubscriptions;
use App\Models\Misc\Setting;
use App\Service\Balance\CurrencyConverter;
use App\Service\Balance\RetrieveBalanceService;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\LiprMpesa\LiprW2W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ISSUE-52 / 53: paying a subscription from the LIPR wallet uses the real balance, charges only on confirm, and trials are free once. */
class SubscriptionWalletPaymentTest extends TestCase
{
    use RefreshDatabase;

    public ?float $balance = 100000;
    public array $transfers = [];
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['user_type_id' => 1, 'lipr_wallet' => 'WALLET-USER']);
        Setting::updateOrCreate(['key' => 'platform_lipr_wallet'], ['value' => 'WALLET-TUJITUME']);
        $test = $this;

        $this->app->instance(CurrencyConverter::class, new class extends CurrencyConverter {
            public function __construct() {}
            public function UsdToKes() { return 130; }
        });
        $this->app->instance(LiprAuthService::class, new class extends LiprAuthService {
            public function __construct() {}
            public function authorize() { return 'token'; }
        });
        $this->app->bind(RetrieveBalanceService::class, fn () => new class($test) extends RetrieveBalanceService {
            public function __construct(private $test) {}
            public function lipr(int $userId)
            {
                return $this->test->balance === null ? ['success' => false, 'message' => 'No wallet found'] : ['success' => true, 'data' => ['availableBalance' => $this->test->balance]];
            }
        });
        $this->app->instance(LiprW2W::class, new class($test) extends LiprW2W {
            public function __construct(private $test) {}
            public function send($amount, string $receiver_wallet, string $payer_wallet, $milestone = null, array $options = [])
            {
                $this->test->transfers[] = compact('amount', 'receiver_wallet', 'payer_wallet');

                return ['success' => true, 'reference' => 'TR-'.count($this->test->transfers)];
            }
        });
    }

    private function send(string $url, array $body)
    {
        return $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/'.$url, $body);
    }

    public function test_the_quote_moves_no_money(): void
    {
        $quote = $this->send('lipr-subscription-quote', ['plan' => 'gold'])->assertOk()->json('data');
        $this->assertTrue($quote['wallet_ok']);
        $this->assertEquals(100000, $quote['wallet_balance']);
        $this->assertGreaterThan(29.99 * 130, $quote['amount_kes']);
        // M-Pesa takes whole shillings only
        $this->assertEquals(floor($quote['amount_kes']), $quote['amount_kes']);
        $this->assertTrue($quote['trial_available']);

        $this->balance = 100;
        $this->assertFalse($this->send('lipr-subscription-quote', ['plan' => 'gold'])->json('data.wallet_ok'));
        $this->assertSame([], $this->transfers);
        $this->assertSame(0, BusinessSubscriptions::count());
    }

    public function test_a_wallet_that_covers_the_plan_pays_once_for_a_paid_30_day_plan(): void
    {
        $this->send('lipr-subscription-initiate', ['plan' => 'gold', 'method' => 'wallet', 'purpose' => 'Subscription'])
            ->assertOk()->assertJsonPath('subscribe_via_wallet', true);

        $this->assertCount(1, $this->transfers);
        $this->assertSame(['WALLET-TUJITUME', 'WALLET-USER'], [$this->transfers[0]['receiver_wallet'], $this->transfers[0]['payer_wallet']]);
        $sub = BusinessSubscriptions::sole();
        $this->assertSame('gold', $sub->plan);
        $this->assertFalse((bool) $sub->trial);
        $this->assertEquals(29.99, (float) $sub->amount);
    }

    public function test_a_short_or_unreadable_wallet_is_refused_without_an_stk_push(): void
    {
        $this->balance = 100;
        $this->send('lipr-subscription-initiate', ['plan' => 'silver', 'method' => 'wallet', 'acc_number' => 'wallet_check', 'purpose' => 'Subscription'])
            ->assertStatus(422)->assertJsonPath('subscribe_via_wallet', false);
        $this->balance = null;
        $this->send('lipr-subscription-initiate', ['plan' => 'silver', 'method' => 'wallet', 'purpose' => 'Subscription'])->assertStatus(422);
        // M-Pesa needs a real phone number
        $this->send('lipr-subscription-initiate', ['plan' => 'silver', 'method' => 'mpesa', 'acc_number' => 'wallet_check', 'purpose' => 'Subscription'])->assertStatus(422);

        $this->assertSame([], $this->transfers);
        $this->assertSame(0, BusinessSubscriptions::count());
    }

    public function test_a_trial_is_free_and_only_once(): void
    {
        $this->send('lipr-subscription-initiate', ['plan' => 'silver', 'trial' => 1, 'method' => 'wallet', 'purpose' => 'Subscription'])
            ->assertOk()->assertJsonPath('trial', true);
        $sub = BusinessSubscriptions::sole();
        $this->assertTrue((bool) $sub->trial);
        $this->assertEquals(0, (float) $sub->amount);
        $this->assertSame([], $this->transfers);

        $this->send('lipr-subscription-initiate', ['plan' => 'gold', 'trial' => 1, 'method' => 'wallet', 'purpose' => 'Subscription'])
            ->assertStatus(422)->assertJsonPath('message', 'The free trial has already been used. Choose a paid plan.');
        $this->assertFalse($this->send('lipr-subscription-quote', ['plan' => 'gold'])->json('data.trial_available'));
    }

    public function test_the_payment_check_takes_a_lipr_reference_with_letters(): void
    {
        $this->send('lipr-subscription', ['plan' => 'gold', 'trial' => 0, 'referenceId' => 'LKEC2M162'])
            ->assertOk()->assertJsonPath('status', 'pending');
    }
}
