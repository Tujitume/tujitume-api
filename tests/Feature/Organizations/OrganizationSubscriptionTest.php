<?php

namespace Tests\Feature\Organizations;

use App\Models\Auth\User;
use App\Models\Business\BusinessSubscriptions;
use App\Models\Organizations\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A program organisation's team shares one subscription, kept on the owner; only admins change it. */
class OrganizationSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_team_sees_the_organisations_subscription_and_only_admins_may_buy(): void
    {
        $owner = User::factory()->create(['user_type_id' => 4]);
        $org = Organization::create(['name' => 'Fund', 'owner_user_id' => $owner->id]);
        $owner->update(['organization_id' => $org->id]);
        $member = User::factory()->create(['user_type_id' => 4, 'organization_id' => $org->id]);

        // Paid by M-Pesa: no Stripe subscription. Reading it must not delete it (it used to)
        BusinessSubscriptions::create(['plan' => 'gold', 'investor_id' => $owner->id, 'amount' => 30, 'start_date' => now()->subDays(3),
            'expire_date' => now()->addDays(27), 'token_remaining' => 30, 'trial' => 0, 'stripe_sub_id' => null]);

        $seen = $this->actingAs($member, 'sanctum')->getJson('/api/v1/business/getCurrSubscription')->assertOk()->json('data');
        $this->assertSame('gold', $seen['plan']);
        $this->assertSame('USD', $seen['currency']);
        $this->assertTrue($seen['organisation']);
        $this->assertFalse($seen['can_manage']);
        $this->assertTrue($seen['is_active']);
        $this->assertSame(1, BusinessSubscriptions::count());

        $this->assertTrue($this->actingAs($owner, 'sanctum')->getJson('/api/v1/business/getCurrSubscription')->json('data.can_manage'));

        // A member cannot buy one for the organisation (refused before any payment is asked for)
        $this->actingAs($member, 'sanctum')->postJson('/api/v1/lipr-subscription-initiate', ['plan' => 'silver', 'acc_number' => '0712345678', 'purpose' => 'subscription'])
            ->assertForbidden();
    }

    public function test_an_individual_keeps_their_own_subscription(): void
    {
        $founder = User::factory()->create(['user_type_id' => 1]);
        $res = $this->actingAs($founder, 'sanctum')->getJson('/api/v1/business/getCurrSubscription')->assertNotFound();
        $this->assertFalse($res->json('organisation'));
        $this->assertTrue($res->json('can_manage'));
    }
}
