<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Creating and running a business is for business owners only; the shared /business routes stay open to everyone. */
class BusinessOwnerOnlyRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_other_user_types_cannot_create_or_change_a_business(): void
    {
        foreach ([2, 3, 4, 5] as $type) {
            $user = User::factory()->create(['user_type_id' => $type]);

            $this->actingAs($user)->postJson('/api/v1/business/create-listing', ['title' => 'Not mine'])
                ->assertForbidden()->assertJsonPath('message', 'Only a business owner can do this.');
            $this->actingAs($user)->postJson('/api/v1/business/save_milestone', [])->assertForbidden();
            $this->actingAs($user)->getJson('/api/v1/business/delete_listing/1')->assertForbidden();
            $this->actingAs($user)->getJson('/api/v1/business/business_bids')->assertForbidden();
        }
    }

    public function test_a_business_owner_reaches_the_owner_routes(): void
    {
        $owner = User::factory()->create(['user_type_id' => 1]);

        // Past the role check: the listing's own checks (KYC first, then the form) answer
        $response = $this->actingAs($owner)->postJson('/api/v1/business/create-listing', []);
        $this->assertNotSame('Only a business owner can do this.', $response->json('message'));
    }

    public function test_shared_business_routes_stay_open_to_every_user_type(): void
    {
        $investor = User::factory()->create(['user_type_id' => 2]);

        $this->assertNotSame(403, $this->actingAs($investor)->getJson('/api/v1/business/getCurrSubscription')->status());
        $this->assertNotSame(403, $this->actingAs($investor)->getJson('/api/v1/business/notifications')->status());
    }
}
