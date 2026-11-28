<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Services\ServiceReviews;
use App\Models\Services\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Rating a service keeps services.rating the average out of 5 (it used to add the stars onto the average). */
class ServiceRatingTest extends TestCase
{
    use RefreshDatabase;

    private function service(array $attributes = []): Services
    {
        $owner = User::factory()->create(['user_type_id' => 3]);

        return Services::create($attributes + ['user_id' => $owner->id, 'name' => 'Coaching', 'price' => 1000, 'category' => 'consulting', 'location' => 'Nairobi', 'details' => 'Help', 'active' => 1]);
    }

    public function test_a_new_rating_moves_the_average_instead_of_adding_to_it(): void
    {
        $service = $this->service(['rating' => 3.8, 'rating_count' => 4]);
        $customer = User::factory()->create(['user_type_id' => 1, 'first_name' => 'Amina']);

        // The values in the path only, as the app's link sends them
        $this->actingAs($customer)->getJson("/api/v1/ratingService/{$service->id}/5/Great%20work")->assertOk();

        $service->refresh();
        $this->assertSame(4.04, round((float) $service->rating, 2)); // (3.8 × 4 + 5) / 5
        $this->assertSame(5, (int) $service->rating_count);
        $this->assertDatabaseHas('service_reviews', ['listing_id' => $service->id, 'user_id' => $customer->id, 'rating' => 5, 'text' => 'Great work']);
    }

    public function test_the_first_rating_is_the_average_and_a_stored_total_heals(): void
    {
        $customer = User::factory()->create(['user_type_id' => 1]);
        $fresh = $this->service(['rating' => 0, 'rating_count' => 0]);
        $this->actingAs($customer)->getJson("/api/v1/ratingService/{$fresh->id}/4/%20", ['id' => $fresh->id])->assertOk();
        $this->assertSame(4.0, (float) $fresh->refresh()->rating);
        $this->assertSame(1, (int) $fresh->rating_count);

        // A row broken by the old code holds a total (8.8 over 2 ratings): read as 4.4, then averaged with the new 3
        $broken = $this->service(['rating' => 8.8, 'rating_count' => 2]);
        $this->actingAs($customer)->getJson("/api/v1/ratingService/{$broken->id}/3/ok")->assertOk();
        $this->assertSame(3.93, round((float) $broken->refresh()->rating, 2)); // (4.4 × 2 + 3) / 3
        $this->assertLessThanOrEqual(5, (float) $broken->rating);
    }

    public function test_stars_outside_one_to_five_are_refused(): void
    {
        $service = $this->service(['rating' => 4, 'rating_count' => 1]);
        $this->actingAs(User::factory()->create(['user_type_id' => 1]))->getJson("/api/v1/ratingService/{$service->id}/9/no")->assertStatus(422);
        $this->assertSame(4.0, (float) $service->refresh()->rating);
        $this->assertSame(0, ServiceReviews::where('listing_id', $service->id)->count());
    }
}
