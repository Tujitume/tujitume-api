<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A business owner adds the milestones of their funding request (the save used to fail with a 500 on every add). */
class SaveBusinessMilestoneTest extends TestCase
{
    use RefreshDatabase;

    private function listing(User $owner): Listing
    {
        return Listing::create(['user_id' => $owner->id, 'name' => 'Water kiosks', 'category' => 'Water', 'location' => 'Nairobi', 'investment_needed' => 900000, 'share' => 15]);
    }

    public function test_the_owner_adds_milestones_up_to_the_amount_needed_and_activates(): void
    {
        $owner = User::factory()->create(['user_type_id' => 1]);
        $listing = $this->listing($owner);

        foreach ([[400000, 60], [350000, 60], [150000, 30]] as [$amount, $days]) {
            $this->actingAs($owner)->postJson('/api/v1/business/save_milestone', [
                'title' => "Milestone {$amount}", 'business_id' => $listing->id, 'amount' => $amount, 'n_o_days' => 2, 'time_type' => 'Weeks', 'funding_duration' => $days,
            ])->assertOk();
        }

        $first = $listing->milestones()->orderBy('id')->first();
        $this->assertSame(now()->toDateString(), substr((string) $first->start_date, 0, 10)); // no start date given → today
        $this->assertSame(14, (int) $first->n_o_days); // 2 weeks
        $this->assertSame(3, $listing->milestones()->count());

        $this->actingAs($owner)->postJson('/api/v1/business/save_milestone', [
            'title' => 'Too much', 'business_id' => $listing->id, 'amount' => 1, 'n_o_days' => 1, 'funding_duration' => 1,
        ])->assertStatus(422)->assertJsonPath('message', 'Amount exceeds the total investment needed.');

        $this->actingAs($owner)->getJson("/api/v1/business/activate_milestone/{$listing->id}")->assertOk();
        $this->assertSame(1, (int) $listing->refresh()->active);
    }

    public function test_nobody_else_adds_milestones_or_activates_the_business(): void
    {
        $owner = User::factory()->create(['user_type_id' => 1]);
        $other = User::factory()->create(['user_type_id' => 1]);
        $listing = $this->listing($owner);

        $this->actingAs($other)->postJson('/api/v1/business/save_milestone', [
            'title' => 'Not mine', 'business_id' => $listing->id, 'amount' => 10, 'n_o_days' => 1, 'funding_duration' => 1,
        ])->assertForbidden();
        $this->actingAs($other)->getJson("/api/v1/business/activate_milestone/{$listing->id}")->assertNotFound();
        $this->assertSame(0, $listing->milestones()->count());
    }
}
