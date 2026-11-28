<?php

namespace Tests\Feature\Services;

use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use App\Models\Services\ServiceBooking;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The service provider's tables and the customer's bookings page, on the server, and their old answers. */
class ServiceListsTest extends TestCase
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

    private function api(User $user, string $url, array $query = [])
    {
        return $this->actingAs($user, 'sanctum')->getJson('/api/v1'.$url.($query ? '?'.http_build_query($query) : ''));
    }

    public function test_the_services_routes_answer_for_a_verified_service_provider(): void
    {
        // the services middleware used Auth without importing it, so every /services route failed
        $this->api($this->provider, '/services/dashhome/service')->assertOk()->assertJsonStructure(['services', 'user_email']);
    }

    public function test_my_services_page_on_the_server_and_export_every_match(): void
    {
        foreach (range(1, 22) as $i) {
            $this->service($this->provider, ['category' => 'consulting']);
        }
        foreach (range(1, 5) as $i) {
            $this->service($this->provider, ['category' => 'transport', 'location' => 'Mombasa', 'active' => 0]);
        }
        $this->service($this->provider, ['name' => 'Zawadi Bookkeeping']);
        $this->service($this->other); // someone else's service never shows

        $page = $this->api($this->provider, '/services/dashhome/service', ['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(28, $page->json('data.pagination.total'));
        $this->assertSame(['total' => 28, 'active' => 23, 'inactive' => 5], $page->json('data.stats'));
        $this->assertEquals(['consulting' => 23, 'transport' => 5], collect($page->json('data.facets.category'))->pluck('count', 'value')->all());
        $this->assertEquals(['active' => 23, 'inactive' => 5], collect($page->json('data.facets.status'))->pluck('count', 'value')->all());

        $all = $this->api($this->provider, '/services/dashhome/service', ['export' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(28, $all->json('data.items'));
        $this->assertFalse($all->json('data.export_truncated'));

        $this->assertCount(5, $this->api($this->provider, '/services/dashhome/service', ['export' => 1, 'filters' => ['status' => ['inactive']]])->json('data.items'));
        $this->assertCount(5, $this->api($this->provider, '/services/dashhome/service', ['export' => 1, 'filters' => ['location' => ['Mombasa']]])->json('data.items'));
        $found = $this->api($this->provider, '/services/dashhome/service', ['page' => 1, 'per_page' => 10, 'search' => 'zawadi'])->json('data.items');
        $this->assertSame(['Zawadi Bookkeeping'], collect($found)->pluck('name')->all());

        // no list parameters: the old answer with every service
        $this->assertCount(28, $this->api($this->provider, '/services/dashhome/service')->json('services'));
    }

    public function test_booking_requests_page_and_keep_the_inbox_and_the_service_filter(): void
    {
        $a = $this->service($this->provider, ['name' => 'Audit']);
        $b = $this->service($this->provider, ['name' => 'Logistics', 'category' => 'transport']);
        foreach (range(1, 14) as $i) {
            $this->booking($a, User::factory()->create(['user_type_id' => 1]));
        }
        $this->booking($b, $this->customer, ['note' => 'Need it soon']);
        $this->booking($a, User::factory()->create(['user_type_id' => 1]), ['status' => 'confirmed']); // not a request any more
        $this->booking($this->service($this->other), $this->customer); // another provider's

        // the inbox: pending requests only
        $page = $this->api($this->provider, '/services/service_booking', ['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(15, $page->json('data.pagination.total'));
        $this->assertSame(15, $page->json('data.stats.total'));
        $this->assertEquals(['Audit' => 14, 'Logistics' => 1], collect($page->json('data.facets.service'))->pluck('count', 'value')->all());
        $this->assertIsString($page->json('data.items.0.service'));

        $this->assertCount(15, $this->api($this->provider, '/services/service_booking', ['export' => 1, 'per_page' => 5])->json('data.items'));
        $found = $this->api($this->provider, '/services/service_booking', ['page' => 1, 'per_page' => 10, 'search' => 'amina'])->json('data.items');
        $this->assertSame(['Amina Otieno'], collect($found)->pluck('customer_name')->all());
        $this->assertCount(1, $this->api($this->provider, '/services/service_booking', ['export' => 1, 'filters' => ['category' => ['transport']]])->json('data.items'));

        // ?service_id=: every booking of that service, any status
        $customers = $this->api($this->provider, '/services/service_booking', ['service_id' => $a->id, 'page' => 1, 'per_page' => 10])->assertOk();
        $this->assertSame(15, $customers->json('data.pagination.total'));
        $this->assertEquals(['pending' => 14, 'confirmed' => 1], collect($customers->json('data.facets.status'))->pluck('count', 'value')->all());
        $this->assertCount(1, $this->api($this->provider, '/services/service_booking', ['service_id' => $a->id, 'export' => 1, 'filters' => ['status' => ['confirmed']]])->json('data.items'));

        // the old answers
        $this->assertCount(15, $this->api($this->provider, '/services/service_booking')->assertOk()->json('results'));
        $this->assertCount(15, $this->api($this->provider, '/services/service_booking', ['service_id' => $a->id])->json('results'));
        $this->assertSame(0, ServiceBooking::where('service_owner_id', $this->provider->id)->where('new', 1)->count());

        // another provider's service shows none of it
        $this->assertSame(0, $this->api($this->other, '/services/service_booking', ['service_id' => $a->id, 'page' => 1, 'per_page' => 10])->json('data.pagination.total'));
    }

    public function test_my_bookings_page_filter_and_total_on_the_server(): void
    {
        $service = $this->service($this->provider, ['price' => 500]);
        $other = $this->service($this->provider, ['category' => 'design', 'price' => 200]);
        foreach (range(1, 12) as $i) {
            $this->booking($service, $this->customer, ['date' => now()->addDays($i)->toDateString()]);
        }
        $this->booking($other, $this->customer, ['status' => 'completed', 'paid' => 1, 'agreed_price' => 300, 'date' => now()->subDays(5)->toDateString()]);
        $this->booking($service, User::factory()->create(['user_type_id' => 1])); // someone else's booking

        $page = $this->api($this->customer, '/services/my_booking', ['page' => 1, 'per_page' => 5])->assertOk();
        $this->assertCount(5, $page->json('data.items'));
        $this->assertSame(13, $page->json('data.pagination.total'));
        $stats = $page->json('data.stats');
        $this->assertSame(13, $stats['total']);
        $this->assertSame(12, $stats['pending']);
        $this->assertSame(1, $stats['finished']);
        $this->assertSame(12, $stats['upcoming']);
        $this->assertEquals(6300, $stats['amount_total']);
        $this->assertEquals(300, $stats['paid_total']);
        $this->assertSame(12, $stats['unpaid_count']);
        $this->assertEquals(['paid' => 1, 'unpaid' => 12], collect($page->json('data.facets.payment'))->pluck('count', 'value')->all());
        $this->assertNotEmpty($page->json('data.items.0.provider_name'));

        $this->assertCount(13, $this->api($this->customer, '/services/my_booking', ['export' => 1, 'per_page' => 5])->json('data.items'));
        $this->assertCount(1, $this->api($this->customer, '/services/my_booking', ['export' => 1, 'filters' => ['payment' => ['paid']]])->json('data.items'));
        $this->assertCount(1, $this->api($this->customer, '/services/my_booking', ['export' => 1, 'filters' => ['category' => ['design']]])->json('data.items'));
        $from = now()->addDays(1)->toDateString();
        $to = now()->addDays(3)->toDateString();
        $this->assertCount(3, $this->api($this->customer, '/services/my_booking', ['export' => 1, 'filters' => ['date' => "$from~$to"]])->json('data.items'));

        $this->assertCount(13, $this->api($this->customer, '/services/my_booking')->assertOk()->json('results'));
    }

    public function test_milestones_per_service_page_with_counts_and_totals(): void
    {
        $withWork = $this->service($this->provider, ['name' => 'Bookkeeping']);
        Smilestones::create(['listing_id' => $withWork->id, 'user_id' => $this->provider->id, 'title' => 'One', 'amount' => 100, 'status' => 'Done']);
        Smilestones::create(['listing_id' => $withWork->id, 'user_id' => $this->provider->id, 'title' => 'Two', 'amount' => 250, 'status' => 'To Do']);
        $finished = $this->service($this->provider);
        Smilestones::create(['listing_id' => $finished->id, 'user_id' => $this->provider->id, 'title' => 'Only', 'amount' => 50, 'status' => 'Done']);
        foreach (range(1, 11) as $i) {
            $this->service($this->provider);
        }
        $theirs = $this->service($this->other);
        Smilestones::create(['listing_id' => $theirs->id, 'user_id' => $this->other->id, 'title' => 'Theirs', 'amount' => 999, 'status' => 'Done']);

        $url = '/services/bBQhdsfE_WWe4Q-_f7ieh7Hdhf4F_-all';
        $page = $this->api($this->provider, $url, ['page' => 1, 'per_page' => 5, 'sort' => 'id'])->assertOk();
        $this->assertCount(5, $page->json('data.items'));
        $this->assertSame(13, $page->json('data.pagination.total'));
        $first = $page->json('data.items.0');
        $this->assertSame(2, $first['milestoneCount']);
        $this->assertSame(1, $first['active']);
        $this->assertSame(1, $first['completed']);
        $this->assertEquals(350, $first['totalAmount']);
        $this->assertSame(['services' => 13, 'milestones' => 3, 'active' => 1, 'completed' => 2, 'total_amount' => 400], $page->json('data.stats'));
        $this->assertEquals(['open' => 1, 'done' => 1, 'none' => 11], collect($page->json('data.facets.progress'))->pluck('count', 'value')->all());

        $this->assertCount(13, $this->api($this->provider, $url, ['export' => 1, 'per_page' => 5])->json('data.items'));
        $this->assertSame([$finished->id], collect($this->api($this->provider, $url, ['export' => 1, 'filters' => ['progress' => ['done']]])->json('data.items'))->pluck('id')->all());
        $this->assertSame(['Bookkeeping'], collect($this->api($this->provider, $url, ['export' => 1, 'search' => 'bookkeeping'])->json('data.items'))->pluck('name')->all());

        // the old answer: every service, and now the milestones of all of them (it used to send none)
        $old = $this->api($this->provider, $url)->assertOk();
        $this->assertCount(13, $old->json('business'));
        $this->assertCount(3, $old->json('milestones'));
        $this->assertCount(2, $this->api($this->provider, '/services/bBQhdsfE_WWe4Q-_f7ieh7Hdhf4F_-'.$withWork->id)->json('milestones'));
    }

    public function test_bookings_sort_by_customer_service_and_amount_on_the_server(): void
    {
        $alpha = $this->service($this->provider, ['name' => 'Alpha Audit', 'price' => 300]);
        $zulu = $this->service($this->provider, ['name' => 'zulu Branding', 'price' => 100]);
        $mid = $this->service($this->provider, ['name' => 'Mango Design', 'price' => 900]);
        $baraka = User::factory()->create(['user_type_id' => 1, 'first_name' => 'Baraka', 'last_name' => 'Mwangi']);
        $zuri = User::factory()->create(['user_type_id' => 1, 'first_name' => 'zuri', 'last_name' => 'Kamau']);

        $this->booking($zulu, $this->customer);                          // Amina, 100
        $this->booking($alpha, $zuri, ['agreed_price' => 50]);          // zuri, agreed 50 (service 300)
        $this->booking($mid, $baraka);                                   // Baraka, 900

        $ids = fn (array $query) => collect($this->api($this->provider, '/services/service_booking', $query + ['page' => 1, 'per_page' => 10])->assertOk()->json('data.items'))->pluck('customer_name')->all();
        $this->assertSame(['Amina Otieno', 'Baraka Mwangi', 'zuri Kamau'], $ids(['sort' => 'customer']));
        $this->assertSame(['zuri Kamau', 'Baraka Mwangi', 'Amina Otieno'], $ids(['sort' => '-customer']));
        $this->assertSame(['zuri Kamau', 'Baraka Mwangi', 'Amina Otieno'], $ids(['sort' => 'service'])); // Alpha, Mango, zulu (any case)
        $this->assertSame(['Baraka Mwangi', 'Amina Otieno', 'zuri Kamau'], $ids(['sort' => '-amount'])); // 900, 100, agreed 50

        // the customer's My Bookings
        $this->booking($alpha, $this->customer, ['agreed_price' => 2000]);
        $mine = fn (array $query) => collect($this->api($this->customer, '/services/my_booking', $query + ['page' => 1, 'per_page' => 10])->assertOk()->json('data.items'))->pluck('service')->all();
        $this->assertSame(['Alpha Audit', 'zulu Branding'], $mine(['sort' => 'service']));
        $this->assertSame(['zulu Branding', 'Alpha Audit'], $mine(['sort' => '-service']));
        $this->assertSame(['zulu Branding', 'Alpha Audit'], $mine(['sort' => 'amount'])); // 100, agreed 2000
        $this->assertSame(['Alpha Audit', 'zulu Branding'], $mine(['sort' => '-amount']));
        // and the export keeps the order
        $this->assertSame(['Alpha Audit', 'zulu Branding'], collect($this->api($this->customer, '/services/my_booking', ['export' => 1, 'sort' => '-amount'])->json('data.items'))->pluck('service')->all());
    }

    public function test_an_unverified_service_provider_is_still_turned_away(): void
    {
        $unverified = User::factory()->create(['user_type_id' => 3]);
        $this->api($unverified, '/services/dashhome/service', ['page' => 1])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/services/my_booking?page=1')->assertUnauthorized();
    }

    public function test_my_bookings_describe_their_filters_and_apply_amount_upcoming_and_date(): void
    {
        $service = $this->service($this->provider, ['price' => 500, 'category' => 'audit']);
        $design = $this->service($this->provider, ['price' => 200, 'category' => 'design']);
        $this->booking($service, $this->customer, ['date' => now()->addDays(2)->toDateString()]);                          // 500, upcoming
        $this->booking($design, $this->customer, ['date' => now()->addDays(10)->toDateString(), 'agreed_price' => 150]);  // 150, upcoming
        $this->booking($design, $this->customer, ['date' => now()->subDays(4)->toDateString(), 'paid' => 1, 'status' => 'completed']); // 200, past

        $data = $this->api($this->customer, '/services/my_booking', ['page' => 1, 'per_page' => 10])->assertOk()->json('data');
        $schema = collect($data['filter_schema'])->keyBy('key');
        $this->assertSame(['status', 'payment', 'category', 'date', 'amount', 'upcoming'], $schema->keys()->all());
        $this->assertSame([
            ['value' => 'paid', 'count' => 1, 'label' => 'shared:myBookings.table.paid'],
            ['value' => 'unpaid', 'count' => 2, 'label' => 'shared:myBookings.table.unpaid'],
        ], $schema['payment']['options']);
        $this->assertEquals(['design' => 2, 'audit' => 1], collect($schema['category']['options'])->pluck('count', 'value')->all());
        $this->assertSame([150, 500], [$schema['amount']['min'], $schema['amount']['max']]);
        $this->assertSame([['value' => 'yes', 'count' => 2], ['value' => 'no', 'count' => 1]], $schema['upcoming']['options']);
        $this->assertSame('dateRange', $schema['date']['type']);
        $this->assertEquals(['paid' => 1, 'unpaid' => 2], collect($data['facets']['payment'])->pluck('count', 'value')->all());

        $services = fn (array $filters) => collect($this->api($this->customer, '/services/my_booking', ['export' => 1, 'filters' => $filters])->json('data.items'))->pluck('agreed_price', 'id')->count();
        $this->assertSame(2, $services(['amount' => '150~200']));
        $this->assertSame(1, $services(['amount' => '300~']));
        $this->assertSame(2, $services(['upcoming' => 'yes']));
        $this->assertSame(1, $services(['upcoming' => 'no', 'payment' => ['paid']]));
        $this->assertSame(1, $services(['date' => now()->toDateString().'~'.now()->addDays(7)->toDateString()]));
        $this->assertSame(1, $services(['upcoming' => 'yes', 'category' => ['audit']]));
    }
}
