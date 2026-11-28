<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** investors_fee is a fixed KSh unlock fee: a percentage-sized number is refused, and old percentage rows were fixed. */
class InvestorsFeeIsAFeeTest extends TestCase
{
    use RefreshDatabase;

    private function listing(array $extra = []): Listing
    {
        $owner = User::factory()->create(['user_type_id' => 1]);

        return Listing::create($extra + ['user_id' => $owner->id, 'name' => 'Shamba Fresh', 'details' => 'Fresh produce from Nakuru.',
            'category' => 'Agriculture', 'location' => 'Nakuru, Kenya', 'active' => 1]);
    }

    public function test_an_update_refuses_a_percentage_sized_fee_and_keeps_a_real_one(): void
    {
        $listing = $this->listing(['investors_fee' => 800]);
        $owner = User::findOrFail($listing->user_id);
        $payload = ['id' => $listing->id, 'name' => 'Shamba Fresh', 'details' => 'Fresh produce from Nakuru.'];

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/business/up_listing', $payload + ['investors_fee' => 5])
            ->assertStatus(422);
        $this->assertSame(800, (int) $listing->fresh()->investors_fee);

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/business/up_listing', $payload + ['investors_fee' => 1500])
            ->assertSuccessful();
        $this->assertSame(1500, (int) $listing->fresh()->investors_fee);
    }

    public function test_old_percentage_fees_become_the_default_unlock_fee(): void
    {
        $percent = $this->listing(['investors_fee' => 5]);
        $real = $this->listing(['investors_fee' => 2500]);
        $none = $this->listing(['investors_fee' => null]);

        $migration = require database_path('migrations/2026_11_28_000501_fix_percentage_investors_fees.php');
        $migration->up();

        $this->assertSame(Listing::DEFAULT_INVESTORS_FEE, (int) DB::table('listings')->where('id', $percent->id)->value('investors_fee'));
        $this->assertSame(2500, (int) $real->fresh()->investors_fee);
        $this->assertNull($none->fresh()->investors_fee);
    }
}
