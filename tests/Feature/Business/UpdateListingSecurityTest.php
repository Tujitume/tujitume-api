<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST business/up_listing: only the business owner may change a business, and only the editable fields are saved
 * (investment_needed, share, y_turnover, id_no, tax_pin and system fields such as user_id stay as they were).
 */
class UpdateListingSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['user_type_id' => 1]);
        $this->listing = Listing::create([
            'user_id' => $this->owner->id, 'name' => 'Shamba Fresh', 'details' => 'Fresh produce from Nakuru.',
            'category' => 'Agriculture', 'location' => 'Nakuru, Kenya', 'investment_needed' => 5000, 'share' => 10,
            'y_turnover' => 12000, 'id_no' => '12345678', 'tax_pin' => 'A123456789B', 'active' => 1,
        ]);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['id' => $this->listing->id, 'name' => 'Shamba Fresh Ltd', 'details' => 'Fresh produce and juices from Nakuru.'];
    }

    public function test_another_user_cannot_update_someone_elses_business(): void
    {
        $other = User::factory()->create(['user_type_id' => 1]);

        $this->actingAs($other, 'sanctum')->postJson('/api/v1/business/up_listing', $this->payload())->assertNotFound();

        $this->assertSame('Shamba Fresh', $this->listing->fresh()->name);
    }

    public function test_the_owner_updates_editable_fields_but_protected_fields_do_not_change(): void
    {
        $intruder = User::factory()->create(['user_type_id' => 1]);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/business/up_listing', $this->payload([
            'location' => 'Nairobi, Kenya',
            'investment_needed' => 999999, 'share' => 90, 'y_turnover' => 1, 'id_no' => '00000000', 'tax_pin' => 'HACKED',
            'user_id' => $intruder->id, 'active' => 0, 'amount_collected' => 500000, 'rating' => 5,
            'not_a_column' => 'ignored',
        ]))->assertOk();

        $fresh = $this->listing->fresh();
        $this->assertSame('Shamba Fresh Ltd', $fresh->name);
        $this->assertSame('Nairobi, Kenya', $fresh->location);
        $this->assertEquals(5000, $fresh->investment_needed);
        $this->assertEquals(10, $fresh->share);
        $this->assertEquals(12000, $fresh->y_turnover);
        $this->assertSame('12345678', (string) $fresh->id_no);
        $this->assertSame('A123456789B', $fresh->tax_pin);
        $this->assertSame($this->owner->id, (int) $fresh->user_id);
        $this->assertEquals(1, $fresh->active);
        $this->assertNotEquals(500000, $fresh->amount_collected);
    }
}
