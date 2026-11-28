<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Rating a business keeps listings.rating the average out of 5 (it used to add the stars onto the average). */
class ListingRatingTest extends TestCase
{
    use RefreshDatabase;

    private function listing(array $attributes = []): Listing
    {
        $owner = User::factory()->create(['user_type_id' => 1]);

        return Listing::create($attributes + ['user_id' => $owner->id, 'name' => 'Farm', 'category' => 'Agriculture', 'location' => 'Nairobi, Kenya', 'investment_needed' => 5000]);
    }

    public function test_a_new_rating_moves_the_average_instead_of_adding_to_it(): void
    {
        $listing = $this->listing(['rating' => 3.8, 'rating_count' => 4]);
        $investor = User::factory()->create(['user_type_id' => 2, 'first_name' => 'Wanjiru']);

        $this->actingAs($investor)->getJson("/api/v1/ratingListing/{$listing->id}/5/Solid%20team")->assertOk();

        $listing->refresh();
        $this->assertSame(4.04, round((float) $listing->rating, 2)); // (3.8 × 4 + 5) / 5
        $this->assertSame(5, (int) $listing->rating_count);
        $this->assertDatabaseHas('reviews', ['listing_id' => $listing->id, 'user_id' => $investor->id, 'rating' => 5, 'text' => 'Solid team']);
    }

    public function test_the_first_rating_is_the_average_a_stored_total_heals_and_bad_stars_are_refused(): void
    {
        $investor = User::factory()->create(['user_type_id' => 2]);
        $fresh = $this->listing(['rating' => 0, 'rating_count' => 0]);
        $this->actingAs($investor)->getJson("/api/v1/ratingListing/{$fresh->id}/4/%20")->assertOk();
        $this->assertSame(4.0, (float) $fresh->refresh()->rating);

        // Broken by the old code: a total of 8.8 over 2 ratings reads as 4.4, then averages with the new 3
        $broken = $this->listing(['rating' => 8.8, 'rating_count' => 2]);
        $this->actingAs($investor)->getJson("/api/v1/ratingListing/{$broken->id}/3/ok")->assertOk();
        $this->assertSame(3.93, round((float) $broken->refresh()->rating, 2));

        $this->actingAs($investor)->getJson("/api/v1/ratingListing/{$broken->id}/0/no")->assertStatus(422);
        $this->assertSame(3, (int) $broken->refresh()->rating_count);
    }
}
