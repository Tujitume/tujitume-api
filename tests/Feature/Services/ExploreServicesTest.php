<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use App\Models\Services\ServiceBooking;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use Database\Seeders\VerifiedProviderServicesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Explore services (every live service, with its provider and milestones), booking one, and the seeder behind it. */
class ExploreServicesTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = User::factory()->create(['user_type_id' => 3, 'first_name' => 'Stephen', 'last_name' => 'Provider']);
        KycVerification::create(['user_id' => $this->provider->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);
    }

    public function test_the_seeder_gives_a_verified_provider_twenty_live_services_with_milestones_and_can_run_again(): void
    {
        $this->seed(VerifiedProviderServicesSeeder::class);
        $milestoneIds = Smilestones::orderBy('id')->pluck('id')->all();
        $this->seed(VerifiedProviderServicesSeeder::class);

        $services = Services::where('user_id', $this->provider->id)->with('milestones')->get();
        $this->assertCount(20, $services);
        $this->assertTrue($services->every(fn ($s) => (int) $s->active === 1 && $s->milestones->isNotEmpty()));
        // Each service's milestones add up to its price
        $this->assertTrue($services->every(fn ($s) => (int) $s->milestones->sum('amount') === (int) $s->price));
        // A second run updates in place: same milestones (a booking's mile_id keeps pointing at them)
        $this->assertSame($milestoneIds, Smilestones::orderBy('id')->pluck('id')->all());
    }

    public function test_explore_lists_every_live_service_with_provider_and_milestones_and_the_default_keeps_fifteen(): void
    {
        $this->seed(VerifiedProviderServicesSeeder::class);
        Services::create(['user_id' => $this->provider->id, 'name' => 'Not live', 'price' => 100, 'category' => 'IT', 'location' => 'Nairobi', 'details' => 'Draft', 'pin' => 'SECRET']);
        $org = User::factory()->create(['user_type_id' => 4]);

        $all = $this->actingAs($org, 'sanctum')->getJson('/api/v1/latServicesAuth?all=1')->assertOk()->json('data');
        $this->assertCount(20, $all);
        $first = collect($all)->firstWhere('name', 'Investor-Ready Business Plan');
        $this->assertSame('Stephen Provider', $first['provider_name']);
        $this->assertCount(3, $first['milestones']);
        $this->assertFalse($first['booked']);
        // Only the provider's name and picture: no account, and none of the service's private fields
        $this->assertArrayNotHasKey('owner', $first);
        $this->assertArrayNotHasKey('pin', $first);
        $this->assertArrayNotHasKey('identification', $first);

        $this->assertCount(15, $this->getJson('/api/v1/latServicesAuth')->assertOk()->json('data'));
        $this->assertCount(20, $this->getJson('/api/v1/latServices?all=1')->assertOk()->json('data'));
    }

    public function test_a_program_organisation_books_a_service_once_and_sees_it_as_booked(): void
    {
        $this->seed(VerifiedProviderServicesSeeder::class);
        $org = User::factory()->create(['user_type_id' => 4]);
        $service = Services::where('name', 'Website Design & Build')->firstOrFail();

        $this->actingAs($org, 'sanctum')->postJson('/api/v1/serviceBook', [
            'service_id' => $service->id, 'date' => now()->addDays(5)->toDateString(), 'note' => 'Our programme site',
        ])->assertOk();

        $booking = ServiceBooking::where('booker_id', $org->id)->firstOrFail();
        $this->assertSame('pending', $booking->status);
        $this->assertSame($this->provider->id, (int) $booking->service_owner_id);

        $this->postJson('/api/v1/serviceBook', ['service_id' => $service->id, 'date' => now()->addDays(6)->toDateString()])->assertStatus(409);

        $listed = collect($this->getJson('/api/v1/latServicesAuth?all=1')->json('data'))->firstWhere('id', $service->id);
        $this->assertTrue($listed['booked']);
        // It is in their Service bookings
        $mine = $this->getJson('/api/v1/services/my_booking?page=1')->assertOk();
        $this->assertSame(1, $mine->json('data.pagination.total'));
    }

    public function test_explore_puts_what_suits_the_person_first_and_says_why(): void
    {
        $this->seed(VerifiedProviderServicesSeeder::class);
        $owner = User::factory()->create(['user_type_id' => 4]);
        $org = \App\Models\Organizations\Organization::create(['name' => 'Fund', 'owner_user_id' => $owner->id]);
        $owner->update(['organization_id' => $org->id]);

        // A program organisation runs programmes: Business Coaches come first
        $first = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/latServicesAuth?all=1')->assertOk()->json('data.0');
        $this->assertSame('Business Coach', $first['category']);
        $this->assertContains('role_need', $first['match_reasons']);

        // A teammate keeps opening a marketing service: it rises for the whole team
        $teammate = User::factory()->create(['user_type_id' => 4, 'organization_id' => $org->id]);
        $marketing = Services::where('name', 'Digital Marketing Campaign')->firstOrFail();
        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($teammate, 'sanctum')->postJson("/api/v1/services/{$marketing->id}/viewed", ['seconds' => 300])->assertOk();
        }
        $this->assertDatabaseHas('service_views', ['user_id' => $teammate->id, 'service_id' => $marketing->id, 'views' => 4, 'seconds' => 1200]);

        $before = collect($this->actingAs($owner, 'sanctum')->getJson('/api/v1/latServicesAuth?all=1')->json('data'))->firstWhere('id', $marketing->id);
        // The owner liking it as well makes it their own interest
        \App\Models\Shared\Like::create(['listing_id' => $marketing->id, 'user_id' => $owner->id, 'type' => 'service']);
        $after = collect($this->actingAs($owner, 'sanctum')->getJson('/api/v1/latServicesAuth?all=1')->json('data'))->firstWhere('id', $marketing->id);
        $this->assertGreaterThan(0, $before['match_score']);
        $this->assertGreaterThan($before['match_score'], $after['match_score']);
        $this->assertContains('your_interest', $after['match_reasons']);

        // The list stays in best-match order
        $scores = collect($this->getJson('/api/v1/latServicesAuth?all=1')->json('data'))->pluck('match_score')->all();
        $sorted = $scores; rsort($sorted);
        $this->assertSame($sorted, $scores);
    }

    public function test_an_entrepreneur_gets_business_building_services_first(): void
    {
        $this->seed(VerifiedProviderServicesSeeder::class);
        $founder = User::factory()->create(['user_type_id' => 1]);
        $top = collect($this->actingAs($founder, 'sanctum')->getJson('/api/v1/latServicesAuth?all=1')->json('data'))->take(5)->pluck('category');
        $this->assertTrue($top->contains('Business Planning'));
        $this->assertFalse($top->contains('Public Relations'));

        $this->postJson('/api/v1/services/999999/viewed', ['seconds' => 5])->assertNotFound();
    }
}
