<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use App\Models\Services\ServiceBooking;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The service provider's tables describe their filters (filter_schema) and every described filter answers correctly. */
class ServiceProviderListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;
    private User $other;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->serviceProvider();
        $this->other = $this->serviceProvider();
        $this->customer = User::factory()->create(['user_type_id' => 1, 'first_name' => 'Amina', 'last_name' => 'Otieno']);
    }

    private function serviceProvider(): User
    {
        $user = User::factory()->create(['user_type_id' => 3]);
        KycVerification::create(['user_id' => $user->id, 'verification_type' => 'service_provider', 'status' => 'verified', 'submitted_at' => now(), 'reviewed_at' => now()]);

        return $user;
    }

    private function service(User $owner, array $attributes = []): Services
    {
        static $n = 0;
        $n++;

        return Services::create($attributes + ['user_id' => $owner->id, 'name' => "Service $n", 'price' => 1000, 'category' => 'consulting', 'location' => 'Nairobi', 'details' => 'Help', 'active' => 1]);
    }

    private function booking(Services $service, User $booker, array $attributes = []): ServiceBooking
    {
        return ServiceBooking::create($attributes + ['service_id' => $service->id, 'booker_id' => $booker->id, 'service_owner_id' => $service->user_id, 'date' => now()->addDays(3)->toDateString(), 'status' => 'pending']);
    }

    private function milestone(Services $service, array $attributes = []): Smilestones
    {
        return Smilestones::create($attributes + ['listing_id' => $service->id, 'user_id' => $service->user_id, 'title' => 'Step', 'amount' => 100, 'status' => 'To Do']);
    }

    private function api(User $user, string $url, array $query = [])
    {
        return $this->actingAs($user, 'sanctum')->getJson('/api/v1'.$url.($query ? '?'.http_build_query($query) : ''));
    }

    /** ids of every row matching the filters (export: no paging) */
    private function ids(string $url, array $filters, array $extra = []): array
    {
        return collect($this->api($this->provider, $url, $extra + ['export' => 1, 'filters' => $filters])->assertOk()->json('data.items'))->pluck('id')->sort()->values()->all();
    }

    public function test_my_services_describe_and_apply_status_category_location_price_rating_photo_bookings_milestones_and_created(): void
    {
        $audit = $this->service($this->provider, ['name' => 'Audit', 'price' => 500, 'rating' => 4.5, 'image' => 'https://x.test/a.png', 'category' => 'finance']);
        $design = $this->service($this->provider, ['name' => 'Design', 'price' => 2500, 'rating' => 3, 'location' => 'Mombasa', 'active' => 0]);
        $old = $this->service($this->provider, ['name' => 'Old', 'price' => 900, 'image' => '']);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        $this->service($this->other, ['price' => 99999]); // someone else's
        $this->booking($audit, $this->customer);
        $this->milestone($design);

        $url = '/services/dashhome/service';
        $data = $this->api($this->provider, $url, ['page' => 1, 'per_page' => 10])->assertOk()->json('data');
        $schema = collect($data['filter_schema'])->keyBy('key');
        $this->assertSame(['status', 'category', 'location', 'price', 'rating', 'photo', 'bookings', 'milestones', 'created'], $schema->keys()->all());
        $this->assertSame([
            ['value' => 'active', 'count' => 2, 'label' => 'app:active'],
            ['value' => 'inactive', 'count' => 1, 'label' => 'app:inactive'],
        ], $schema['status']['options']);
        $this->assertEquals(['consulting' => 2, 'finance' => 1], collect($schema['category']['options'])->pluck('count', 'value')->all());
        $this->assertTrue($schema['location']['searchable']);
        $this->assertSame([500, 2500], [$schema['price']['min'], $schema['price']['max']]);
        $this->assertSame('numberRange', $schema['rating']['type']);
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 2]], $schema['photo']['options']);
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 2]], $schema['bookings']['options']);
        $this->assertSame('dateRange', $schema['created']['type']);
        $this->assertContains('last30Days', $schema['created']['presets']);
        // the old facets keep their shape
        $this->assertEquals(['active' => 2, 'inactive' => 1], collect($data['facets']['status'])->pluck('count', 'value')->all());

        $this->assertSame([$design->id], $this->ids($url, ['status' => ['inactive']]));
        $this->assertSame([$audit->id], $this->ids($url, ['category' => ['finance']]));
        $this->assertSame([$design->id], $this->ids($url, ['location' => ['Mombasa']]));
        $this->assertSame([$audit->id, $old->id], $this->ids($url, ['price' => '~1000']));
        $this->assertSame([$audit->id], $this->ids($url, ['rating' => '4~']));
        $this->assertSame([$audit->id], $this->ids($url, ['photo' => 'yes']));
        $this->assertSame([$design->id, $old->id], $this->ids($url, ['photo' => 'no']));
        $this->assertSame([$audit->id], $this->ids($url, ['bookings' => 'yes']));
        $this->assertSame([$design->id], $this->ids($url, ['milestones' => 'yes']));
        $this->assertSame([$old->id], $this->ids($url, ['created' => '~'.now()->subDays(30)->toDateString()]));
        $this->assertSame([$audit->id], $this->ids($url, ['status' => ['active'], 'price' => '~600']));
    }

    public function test_booking_requests_describe_and_apply_service_customer_payment_dates_amount_upcoming_and_offer(): void
    {
        $audit = $this->service($this->provider, ['name' => 'Audit', 'price' => 500, 'category' => 'finance', 'location' => 'Kisumu']);
        $design = $this->service($this->provider, ['name' => 'Design', 'price' => 200]);
        $baraka = User::factory()->create(['user_type_id' => 1, 'first_name' => 'Baraka', 'last_name' => 'Mwangi']);

        $soon = $this->booking($audit, $this->customer, ['date' => now()->addDays(2)->toDateString()]);
        $later = $this->booking($design, $baraka, ['date' => now()->addDays(20)->toDateString(), 'agreed_price' => 150, 'is_offer_booking' => 1]);
        $past = $this->booking($design, $this->customer, ['date' => now()->subDays(3)->toDateString(), 'paid' => 1]);
        $past->forceFill(['created_at' => now()->subDays(10)])->save();
        $this->booking($audit, $baraka, ['status' => 'confirmed']); // not in the inbox

        $url = '/services/service_booking';
        $data = $this->api($this->provider, $url, ['page' => 1, 'per_page' => 10])->assertOk()->json('data');
        $schema = collect($data['filter_schema'])->keyBy('key');
        $this->assertSame(['status', 'stage', 'payment', 'service', 'category', 'location', 'customer', 'date', 'created', 'amount', 'upcoming', 'offer'], $schema->keys()->all());
        $this->assertSame([['value' => 'pending', 'count' => 3, 'label' => 'shared:myBookings.bookingsListUi.statuses.pending']], $schema['status']['options']);
        $this->assertEquals(['paid' => 1, 'unpaid' => 2], collect($schema['payment']['options'])->pluck('count', 'value')->all());
        $this->assertEquals([(string) $design->id => 'Design', (string) $audit->id => 'Audit'], collect($schema['service']['options'])->pluck('label', 'value')->all());
        $this->assertEquals([(string) $design->id => 2, (string) $audit->id => 1], collect($schema['service']['options'])->pluck('count', 'value')->all());
        $this->assertEquals(['Amina Otieno' => 2, 'Baraka Mwangi' => 1], collect($schema['customer']['options'])->pluck('count', 'label')->all());
        $this->assertEquals(['Nairobi' => 2, 'Kisumu' => 1], collect($schema['location']['options'])->pluck('count', 'value')->all());
        $this->assertSame([150, 500], [$schema['amount']['min'], $schema['amount']['max']]);
        $this->assertContains('next30Days', $schema['date']['presets']);
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 2]], $schema['offer']['options']);
        // the old facets keep the service names
        $this->assertEquals(['Design' => 2, 'Audit' => 1], collect($data['facets']['service'])->pluck('count', 'value')->all());

        $this->assertSame([$later->id, $past->id], $this->ids($url, ['service' => [$design->id]]));
        $this->assertSame([$later->id, $past->id], $this->ids($url, ['service' => ['Design']])); // older links by name
        $this->assertSame([$soon->id], $this->ids($url, ['category' => ['finance']]));
        $this->assertSame([$soon->id], $this->ids($url, ['location' => ['Kisumu']]));
        $this->assertSame([$later->id], $this->ids($url, ['customer' => [$baraka->id]]));
        $this->assertSame([$past->id], $this->ids($url, ['payment' => ['paid']]));
        $this->assertSame([$soon->id, $later->id], $this->ids($url, ['upcoming' => 'yes']));
        $this->assertSame([$soon->id], $this->ids($url, ['date' => now()->toDateString().'~'.now()->addDays(7)->toDateString()]));
        $this->assertSame([$past->id], $this->ids($url, ['created' => '~'.now()->subDays(5)->toDateString()]));
        $this->assertSame([$later->id, $past->id], $this->ids($url, ['amount' => '~200']));
        $this->assertSame([$later->id], $this->ids($url, ['offer' => 'yes']));
    }

    public function test_a_services_customers_drop_the_service_filters_and_filter_by_status_and_stage(): void
    {
        $audit = $this->service($this->provider, ['name' => 'Audit']);
        $pending = $this->booking($audit, $this->customer);
        $confirmed = $this->booking($audit, $this->customer, ['status' => 'confirmed', 'stage' => 'Kick-off']);

        $url = '/services/service_booking';
        $schema = collect($this->api($this->provider, $url, ['service_id' => $audit->id, 'page' => 1, 'per_page' => 10])->assertOk()->json('data.filter_schema'))->keyBy('key');
        $this->assertSame(['status', 'stage', 'payment', 'customer', 'date', 'created', 'amount', 'upcoming', 'offer'], $schema->keys()->all());
        $this->assertEquals(['pending' => 1, 'confirmed' => 1], collect($schema['status']['options'])->pluck('count', 'value')->all());
        $this->assertEquals(['Pending' => 1, 'Kick-off' => 1], collect($schema['stage']['options'])->pluck('count', 'value')->all());

        $this->assertSame([$confirmed->id], $this->ids($url, ['status' => ['confirmed']], ['service_id' => $audit->id]));
        $this->assertSame([$pending->id], $this->ids($url, ['stage' => ['Pending']], ['service_id' => $audit->id]));
    }

    public function test_milestones_per_service_describe_and_apply_progress_counts_totals_price_active_and_created(): void
    {
        $open = $this->service($this->provider, ['name' => 'Open', 'price' => 300, 'category' => 'finance']);
        $this->milestone($open, ['amount' => 100, 'status' => 'Done']);
        $this->milestone($open, ['amount' => 250]);
        $done = $this->service($this->provider, ['name' => 'Done', 'price' => 800, 'active' => 0]);
        $this->milestone($done, ['amount' => 50, 'status' => 'Done']);
        $none = $this->service($this->provider, ['name' => 'None', 'price' => 1200, 'location' => 'Mombasa']);
        $none->forceFill(['created_at' => now()->subDays(60)])->save();
        $this->milestone($this->service($this->other), ['amount' => 9999]);

        $url = '/services/bBQhdsfE_WWe4Q-_f7ieh7Hdhf4F_-all';
        $data = $this->api($this->provider, $url, ['page' => 1, 'per_page' => 10])->assertOk()->json('data');
        $schema = collect($data['filter_schema'])->keyBy('key');
        $this->assertSame(['progress', 'category', 'location', 'count', 'total', 'price', 'active', 'created'], $schema->keys()->all());
        $this->assertSame([
            ['value' => 'open', 'count' => 1, 'label' => 'app:active'],
            ['value' => 'done', 'count' => 1, 'label' => 'app:completed'],
            ['value' => 'none', 'count' => 1, 'label' => 'app:noMilestonesYet'],
        ], $schema['progress']['options']);
        $this->assertSame([0, 2], [$schema['count']['min'], $schema['count']['max']]);
        $this->assertSame([0, 350], [$schema['total']['min'], $schema['total']['max']]);
        $this->assertSame([300, 1200], [$schema['price']['min'], $schema['price']['max']]);
        $this->assertSame([['value' => 'yes', 'count' => 2], ['value' => 'no', 'count' => 1]], $schema['active']['options']);
        $this->assertEquals(['open' => 1, 'done' => 1, 'none' => 1], collect($data['facets']['progress'])->pluck('count', 'value')->all());

        $this->assertSame([$open->id, $none->id], $this->ids($url, ['progress' => ['open', 'none']]));
        $this->assertSame([$open->id], $this->ids($url, ['category' => ['finance']]));
        $this->assertSame([$none->id], $this->ids($url, ['location' => ['Mombasa']]));
        $this->assertSame([$open->id, $done->id], $this->ids($url, ['count' => '1~']));
        $this->assertSame([$open->id], $this->ids($url, ['total' => '300~']));
        $this->assertSame([$done->id, $none->id], $this->ids($url, ['price' => '500~']));
        $this->assertSame([$done->id], $this->ids($url, ['active' => 'no']));
        $this->assertSame([$none->id], $this->ids($url, ['created' => '~'.now()->subDays(30)->toDateString()]));
    }
}
