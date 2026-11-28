<?php

namespace Tests\Feature\Programs;

use App\Models\Business\BusinessSubscriptions;
use App\Service\Subscription\SubscriptionAccess;

/** A program organisation without an active plan cannot create, import or publish programs (like KYC). */
class SubscriptionRequiredTest extends ProgramTestCase
{
    private function dropPlan(): void
    {
        BusinessSubscriptions::where('investor_id', $this->orgUser->id)->delete();
    }

    public function test_without_a_plan_creating_importing_and_publishing_is_refused(): void
    {
        $this->dropPlan();
        $this->actingAsOrgOwner();

        $this->postJson('/api/v1/programs/create-program', ['program_title' => 'X'])
            ->assertStatus(402)->assertJsonPath('message', SubscriptionAccess::MESSAGE);
        $this->postJson('/api/v1/programs/imports', [])->assertStatus(402);
        $this->postJson('/api/v1/programs/rounds/'.$this->round->id.'/publish')->assertStatus(402);

        $this->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('data.subscription_active', false);
    }

    public function test_an_expired_or_switched_off_plan_does_not_count(): void
    {
        $this->dropPlan();
        BusinessSubscriptions::create(['plan' => 'gold', 'investor_id' => $this->orgUser->id, 'amount' => 29.99, 'trial' => 0,
            'start_date' => now()->subMonths(2), 'expire_date' => now()->subDay(), 'active' => 1]);
        $this->assertTrue(SubscriptionAccess::blocked($this->orgUser->fresh()));

        BusinessSubscriptions::create(['plan' => 'gold', 'investor_id' => $this->orgUser->id, 'amount' => 29.99, 'trial' => 0,
            'start_date' => now(), 'expire_date' => now()->addMonth(), 'active' => 0]);
        $this->assertTrue(SubscriptionAccess::blocked($this->orgUser->fresh()));
    }

    public function test_with_a_plan_the_whole_team_may_work_and_other_accounts_are_not_asked(): void
    {
        $this->assertFalse(SubscriptionAccess::blocked($this->orgUser));
        $this->actingAsOrgOwner()->getJson('/api/v1/users/me')->assertJsonPath('data.subscription_active', true);

        // A team member shares the owner's plan
        $member = \App\Models\Auth\User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
        $this->assertFalse(SubscriptionAccess::blocked($member));

        // An entrepreneur is not asked for a plan here
        $this->assertFalse(SubscriptionAccess::blocked($this->applicantUser));
    }
}
