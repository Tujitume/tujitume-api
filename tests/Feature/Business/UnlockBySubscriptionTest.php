<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Unlocking a business with a subscription: your own running plan only, one unlock used per business, none past the plan's allowance. */
class UnlockBySubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function sub(User $user, int $tokens, $expires = null): int
    {
        return DB::table('business_subscriptions')->insertGetId(['plan' => 'silver', 'investor_id' => $user->id, 'amount' => 9.99, 'token_remaining' => $tokens, 'trial' => 0,
            'start_date' => now(), 'expire_date' => $expires ?? now()->addDays(30), 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_an_unlock_uses_one_token_once_and_only_from_your_own_running_plan(): void
    {
        $owner = User::factory()->create(['user_type_id' => 1]);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Kiosks', 'category' => 'Water', 'location' => 'Nairobi', 'investment_needed' => 1000, 'share' => 10]);
        $subId = $this->sub($investor, 2);

        $other = User::factory()->create(['user_type_id' => 2]);
        $this->actingAs($other)->getJson("/api/v1/unlockBySubs/{$listing->id}/{$subId}/gold")->assertForbidden();

        $this->actingAs($investor)->getJson("/api/v1/unlockBySubs/{$listing->id}/{$subId}/gold")->assertOk()->assertJsonPath('unlocks_left', 1);
        $this->actingAs($investor)->getJson("/api/v1/unlockBySubs/{$listing->id}/{$subId}/gold")->assertOk()->assertJsonPath('already_unlocked', true);
        $this->assertSame(1, (int) DB::table('business_subscriptions')->find($subId)->token_remaining);
        $this->assertSame(1, DB::table('conversations')->where('investor_id', $investor->id)->where('listing_id', $listing->id)->count());

        $second = Listing::create(['user_id' => $owner->id, 'name' => 'Farm', 'category' => 'Agri', 'location' => 'Nakuru', 'investment_needed' => 1000, 'share' => 10]);
        $third = Listing::create(['user_id' => $owner->id, 'name' => 'Shop', 'category' => 'Retail', 'location' => 'Kisumu', 'investment_needed' => 1000, 'share' => 10]);
        $this->actingAs($investor)->getJson("/api/v1/unlockBySubs/{$second->id}/{$subId}/silver")->assertOk();
        $this->actingAs($investor)->getJson("/api/v1/unlockBySubs/{$third->id}/{$subId}/silver")->assertStatus(422); // none left

        $expired = $this->sub($investor, 5, now()->subDay());
        $this->actingAs($investor)->getJson("/api/v1/unlockBySubs/{$third->id}/{$expired}/silver")->assertForbidden();
    }
}
