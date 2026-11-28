<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ISSUE-47: POST services/save_s_milestone (a provider adds a milestone to their service). */
class SaveServiceMilestoneTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;
    private Services $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->verifiedProvider();
        $this->service = Services::create(['user_id' => $this->provider->id, 'name' => 'Website Design', 'price' => 10000, 'category' => 'IT', 'location' => 'Nairobi', 'details' => 'A site', 'active' => 1]);
    }

    private function verifiedProvider(): User
    {
        $user = User::factory()->create(['user_type_id' => 3]);
        KycVerification::create(['user_id' => $user->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);

        return $user;
    }

    private function save(User $user, int $amount, string $title = 'Design')
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/services/save_s_milestone', [
            'title' => $title, 'business_id' => $this->service->id, 'amount' => $amount, 'n_o_days' => 2, 'time_type' => 'Weeks',
        ]);
    }

    public function test_the_provider_adds_milestones_first_to_do_then_on_hold(): void
    {
        $this->save($this->provider, 4000)->assertOk();
        $this->save($this->provider, 6000, 'Build')->assertOk();

        $rows = Smilestones::where('listing_id', $this->service->id)->orderBy('id')->get();
        $this->assertSame(['To Do', 'On Hold'], $rows->pluck('status')->all());
        $this->assertEquals(14, $rows[0]->n_o_days);
    }

    public function test_more_than_the_service_price_is_refused(): void
    {
        $this->save($this->provider, 8000)->assertOk();
        $this->save($this->provider, 3000, 'Extra')->assertStatus(422)->assertJsonPath('message', 'The amount exceeds the service price.');
        $this->assertSame(1, Smilestones::where('listing_id', $this->service->id)->count());
    }

    public function test_another_provider_cannot_add_to_the_service(): void
    {
        $other = $this->verifiedProvider();
        $this->save($other, 1000)->assertStatus(403);
        $this->assertSame(0, Smilestones::count());
    }
}
