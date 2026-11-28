<?php

use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Users\InvestorProfile;
use App\Service\AiScore\ListingScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class, RefreshDatabase::class);

// What the investor sign-up form sends (labels as written on the form)
function invPrefsSignUp(array $overrides = []): array
{
    return array_merge([
        'user_type_id' => 2,
        'first_name' => 'Wanjiru', 'last_name' => 'Kamau', 'gender' => 'Female', 'dob' => '1990-04-02',
        'email' => 'wanjiru.investor@tujitume.test', 'password' => 'Investor#2026', 'password_confirmation' => 'Investor#2026',
        'inv_range' => ['KES 250,000 - 500,000', 'KES 500,000+'],
        'turnover_range' => ['KES 100,000 - 250,000'],
        'interested_sectors' => ['Agriculture', 'Renewable Energy'],
        'stage' => ['Seed', 'Growth'],
        'regions_focus' => ['Kenya'],
        'social_impact_areas' => ['Job Creation'],
        'past_investment' => 'Two dairy co-ops.',
    ], $overrides);
}

function invPrefsBusiness(array $attributes = []): Listing
{
    return Listing::create(array_merge(['name' => 'Solar kiosks',
        'user_id' => User::factory()->create(['user_type_id' => 1])->id,
        'category' => 'Renewable-Energy', 'stage' => 'seed', 'location' => 'Nakuru', 'registration_country' => 'KE',
        'investment_needed' => 800000, 'y_turnover' => '200000', 'social_impact_areas' => ['Job Creation'],
        'rating' => 0, 'rating_count' => 0,
    ], $attributes));
}

it('signs an investor up with their preferences saved', function () {
    Storage::fake('s3');
    $this->postJson('/api/v1/register', invPrefsSignUp())->assertCreated();

    $user = User::where('email', 'wanjiru.investor@tujitume.test')->firstOrFail();
    expect((int) $user->user_type_id)->toBe(2)
        ->and($user->investor_profile->interested_sectors)->toBe(['Agriculture', 'Renewable Energy'])
        ->and($user->investor_profile->regions_focus)->toBe(['Kenya']);
});

it('scores a business on the sign-up preferences, whatever way each side writes them', function () {
    $investor = User::factory()->create(['user_type_id' => 2]);
    InvestorProfile::create(['user_id' => $investor->id] + collect(invPrefsSignUp())->only(['inv_range', 'turnover_range', 'interested_sectors', 'stage', 'regions_focus', 'social_impact_areas'])->all());

    $score = (new ListingScore($investor->fresh()))->calculate(invPrefsBusiness());

    // "KES 500,000+" has no top, "Renewable-Energy" = "Renewable Energy", "seed" = "Seed", KE = Kenya
    expect($score['breakdown'])->toMatchArray(['investment' => 10, 'turnover' => 8, 'stage' => 5, 'sector' => 4, 'region' => 3, 'impact' => 5])
        ->and($score['total'])->toEqual(36);
});

it('gives no criteria points when the business is outside every preference', function () {
    $investor = User::factory()->create(['user_type_id' => 2]);
    InvestorProfile::create(['user_id' => $investor->id] + collect(invPrefsSignUp(['inv_range' => ['KES 0 - 10,000']]))->only(['inv_range', 'turnover_range', 'interested_sectors', 'stage', 'regions_focus'])->all());

    $score = (new ListingScore($investor->fresh()))->calculate(invPrefsBusiness(['category' => 'Fashion', 'stage' => 'mature', 'location' => 'Kampala', 'registration_country' => 'UG', 'y_turnover' => '9000000']));

    expect(collect($score['breakdown'])->except('rating_likes')->sum())->toEqual(0);
});

it('tells the matches page when the investor has no preferences yet', function () {
    invPrefsBusiness();
    $investor = User::factory()->create(['user_type_id' => 2]);
    $this->actingAs($investor, 'sanctum')->getJson('/api/v1/ai/investor/listings?page=1&per_page=10')
        ->assertOk()->assertJsonPath('data.stats.has_preferences', false);

    InvestorProfile::create(['user_id' => $investor->id, 'inv_range' => ['KES 500,000+'], 'turnover_range' => [], 'interested_sectors' => [], 'stage' => [], 'regions_focus' => []]);
    $this->actingAs($investor->fresh(), 'sanctum')->getJson('/api/v1/ai/investor/listings?page=1&per_page=10')
        ->assertOk()->assertJsonPath('data.stats.has_preferences', true);
});

it('lets an investor change their preferences, and only an investor', function () {
    $investor = User::factory()->create(['user_type_id' => 2]);
    $body = collect(invPrefsSignUp())->only(['inv_range', 'turnover_range', 'interested_sectors', 'stage', 'regions_focus', 'social_impact_areas', 'past_investment'])->all();

    $this->actingAs($investor, 'sanctum')->patchJson('/api/v1/users/me/investor-preferences', $body)->assertOk();
    expect($investor->fresh()->investor_profile->stage)->toBe(['Seed', 'Growth']);

    $this->actingAs($investor, 'sanctum')->patchJson('/api/v1/users/me/investor-preferences', ['stage' => ['Idea']] + $body)->assertOk();
    expect(InvestorProfile::where('user_id', $investor->id)->count())->toBe(1)
        ->and($investor->fresh()->investor_profile->stage)->toBe(['Idea']);

    $this->actingAs($investor, 'sanctum')->patchJson('/api/v1/users/me/investor-preferences', ['stage' => []] + $body)->assertStatus(422);

    $owner = User::factory()->create(['user_type_id' => 1]);
    $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/users/me/investor-preferences', $body)->assertForbidden();
});
