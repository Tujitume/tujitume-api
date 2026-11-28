<?php

namespace Tests\Feature\Organizations;

use App\Models\Auth\User;
use App\Models\Business\BusinessSubscriptions;
use Stripe\StripeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ISSUE-51: only the person who manages a Stripe subscription may cancel or renew it, and only by POST. */
class SubscriptionRenewCancelTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array> Stripe updates the fake client was asked for */
    public array $updates = [];

    protected function setUp(): void
    {
        parent::setUp();
        $test = $this;
        $this->app->instance(StripeClient::class, new class($test) extends StripeClient {
            public function __construct(private $test) { parent::__construct(['api_key' => 'sk_test_fake']); }
            public function __get($name)
            {
                $test = $this->test;

                return $name === 'subscriptions'
                    ? new class($test) { public function __construct(private $test) {} public function update($id, $params) { $this->test->updates[] = [$id, $params]; return (object) []; } }
                    : parent::__get($name);
            }
        });
    }

    private function subscriptionOf(User $user): BusinessSubscriptions
    {
        return BusinessSubscriptions::create(['plan' => 'gold', 'investor_id' => $user->id, 'amount' => 30, 'start_date' => now(), 'expire_date' => now()->addDays(30),
            'token_remaining' => 30, 'trial' => 0, 'stripe_sub_id' => 'sub_'.$user->id]);
    }

    public function test_someone_else_cannot_renew_or_cancel_a_subscription(): void
    {
        $owner = User::factory()->create(['user_type_id' => 1]);
        $stranger = User::factory()->create(['user_type_id' => 1]);
        $sub = $this->subscriptionOf($owner);

        $this->actingAs($stranger, 'sanctum')->postJson('/api/v1/business/renewSubscription/'.$sub->stripe_sub_id)->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->postJson('/api/v1/business/cancelSubscription/'.$sub->stripe_sub_id)->assertNotFound();
        $this->assertSame([], $this->updates);
    }

    public function test_the_owner_cancels_and_renews_by_post_only(): void
    {
        $owner = User::factory()->create(['user_type_id' => 1]);
        $sub = $this->subscriptionOf($owner);

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/business/renewSubscription/'.$sub->stripe_sub_id)->assertStatus(405);
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/business/cancelSubscription/'.$sub->stripe_sub_id)->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/business/renewSubscription/'.$sub->stripe_sub_id)->assertOk();
        $this->assertSame([[$sub->stripe_sub_id, ['cancel_at_period_end' => true]], [$sub->stripe_sub_id, ['cancel_at_period_end' => false]]], $this->updates);
    }
}
