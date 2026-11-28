<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** POST services/activate: a service goes live only when its milestones add up to its price (what the customer pays). */
class ActivateServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;
    private Services $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = User::factory()->create(['user_type_id' => 3]);
        KycVerification::create(['user_id' => $this->provider->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);
        $this->service = Services::create(['user_id' => $this->provider->id, 'name' => 'Business Plan', 'price' => 30000, 'category' => 'Business Planning', 'location' => 'Nairobi', 'details' => 'A plan']);
    }

    private function milestone(int $amount, string $title): void
    {
        Smilestones::create(['user_id' => $this->provider->id, 'listing_id' => $this->service->id, 'title' => $title, 'amount' => $amount, 'n_o_days' => 7, 'status' => 'To Do']);
    }

    private function activate()
    {
        return $this->actingAs($this->provider, 'sanctum')->postJson('/api/v1/services/activate', ['id' => $this->service->id]);
    }

    public function test_milestones_short_of_the_price_cannot_go_live(): void
    {
        $this->milestone(8000, 'Discovery');
        $this->activate()->assertStatus(422)->assertJsonPath('message', 'The milestones must add up to the service price (KES 30,000). They add up to KES 8,000 now: add or change a milestone, then activate.');
        $this->assertNotEquals(1, (int) $this->service->fresh()->active);
    }

    public function test_milestones_adding_up_to_the_price_go_live(): void
    {
        $this->milestone(8000, 'Discovery');
        $this->milestone(22000, 'Model and plan');
        $this->activate()->assertOk();
        $this->assertSame(1, (int) $this->service->fresh()->active);
    }

    public function test_a_service_without_milestones_is_still_refused(): void
    {
        $this->activate()->assertStatus(400);
    }
}
