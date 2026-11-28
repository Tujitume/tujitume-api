<?php

namespace Tests\Feature\Shared;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Communication\Meeting;
use App\Models\Misc\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The shared "My events", meetings and business recommendations tables page, search, filter and export on the server. */
class SharedListsTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create(['user_type_id' => 1]);
    }

    private function fetch(string $url, array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->me, 'sanctum')->getJson($url.($query ? '?'.http_build_query($query) : ''));
    }

    private function makeEvents(int $count, array $attributes = [], ?User $owner = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            Event::create($attributes + [
                'user_id' => ($owner ?? $this->me)->id,
                'event_name' => "Summit $i",
                'event_type' => 'conference',
                'sector' => 'Agriculture',
                'start_date' => now()->addDays(10 + $i)->toDateTimeString(),
                'end_date' => now()->addDays(11 + $i)->toDateTimeString(),
                'timezone' => 'Africa/Nairobi',
                'location_type' => 'in_person',
                'city' => 'Nairobi',
                'description' => 'About',
                'cost_type' => 'paid',
                'price' => 500,
                'currency' => 'KES',
                'cover_image' => 'events/cover.jpg',
                'active' => 1,
            ]);
        }
    }

    public function test_my_events_page_filter_search_and_export_on_the_server(): void
    {
        $this->makeEvents(22);
        $this->makeEvents(4, [
            'event_name' => 'Virtual Pitch Night', 'event_type' => 'webinar', 'location_type' => 'virtual', 'cost_type' => 'free', 'price' => null, 'active' => 0,
            'start_date' => now()->subDays(5)->toDateTimeString(), 'end_date' => now()->subDays(4)->toDateTimeString(),
        ]);
        $this->makeEvents(3, [], User::factory()->create()); // someone else's never show

        $page = $this->fetch('/api/v1/events', ['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(26, $page->json('data.pagination.total'));
        $this->assertSame(26, $page->json('data.stats.total'));
        $this->assertSame(22, $page->json('data.stats.upcoming'));
        $this->assertSame(4, $page->json('data.stats.ended'));
        $this->assertSame(4, $page->json('data.stats.virtual'));
        $this->assertSame(4, $page->json('data.stats.inactive'));
        $this->assertSame('Summit 0', $page->json('data.stats.next.event_name'));
        $this->assertEquals(['conference' => 22, 'webinar' => 4], collect($page->json('data.facets.type'))->pluck('count', 'value')->all());
        $this->assertEquals(['free' => 4, 'paid' => 22], collect($page->json('data.facets.cost'))->pluck('count', 'value')->all());

        $all = $this->fetch('/api/v1/events', ['export' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(26, $all->json('data.items'));
        $this->assertFalse($all->json('data.export_truncated'));

        foreach ([['timing' => ['ended']], ['format' => ['virtual']], ['status' => ['inactive']], ['cost' => ['free']]] as $filters) {
            $this->assertCount(4, $this->fetch('/api/v1/events', ['export' => 1, 'filters' => $filters])->json('data.items'));
        }
        $this->assertSame(22, $this->fetch('/api/v1/events', ['page' => 1, 'per_page' => 5, 'filters' => ['timing' => ['upcoming']]])->json('data.pagination.total'));
        $this->assertCount(4, $this->fetch('/api/v1/events', ['page' => 1, 'per_page' => 10, 'search' => 'pitch night'])->json('data.items'));
        $this->assertSame('Summit 21', $this->fetch('/api/v1/events', ['page' => 1, 'per_page' => 1, 'sort' => '-start'])->json('data.items.0.event_name'));

        // no params: the old full list
        $this->assertCount(26, $this->fetch('/api/v1/events')->json('events'));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/events?page=1')->assertUnauthorized();
    }

    private function meeting(User $host, User $client, array $attributes = []): Meeting
    {
        return Meeting::create($attributes + [
            'host_id' => $host->id, 'client_id' => $client->id,
            'host_name' => trim($host->first_name.' '.$host->last_name), 'client_name' => trim($client->first_name.' '.$client->last_name),
            'date' => now()->addDays(3)->toDateString(), 'time' => '10:00',
            'title' => 'Catch up', 'description' => null, 'link' => 'https://meet.example.test/x', 'status' => 'pending',
        ]);
    }

    public function test_meetings_page_filter_search_and_export_on_the_server(): void
    {
        $guests = User::factory()->count(2)->create();
        for ($i = 0; $i < 15; $i++) {
            // one meeting with two people invited: two rows, one meeting for the host
            $created = now()->subMinutes($i);
            foreach ($guests as $guest) {
                $m = $this->meeting($this->me, $guest, ['title' => "Weekly $i"]);
                $m->created_at = $created;
                $m->save();
            }
        }
        $host = User::factory()->create(['first_name' => 'Zawadi', 'last_name' => 'Host']);
        $this->meeting($host, $this->me, ['title' => 'Old review', 'date' => now()->subDays(3)->toDateString()]);
        $this->meeting($host, $this->me, ['title' => 'Declined chat', 'status' => 'declined']);
        // not mine
        $this->meeting($host, $guests[0], ['title' => 'Private']);

        $page = $this->fetch('/api/v1/meetings', ['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(17, $page->json('data.pagination.total'));
        $this->assertSame(['all' => 17, 'upcoming' => 15, 'past' => 1, 'cancelled' => 1], $page->json('data.stats'));
        $this->assertCount(2, $page->json('data.items.0.attendees'));

        $this->assertCount(17, $this->fetch('/api/v1/meetings', ['export' => 1, 'per_page' => 5])->json('data.items'));
        $this->assertSame(['Old review'], collect($this->fetch('/api/v1/meetings', ['export' => 1, 'filters' => ['bucket' => ['past']]])->json('data.items'))->pluck('title')->all());
        $this->assertSame(['Declined chat'], collect($this->fetch('/api/v1/meetings', ['page' => 1, 'per_page' => 10, 'filters' => ['bucket' => ['cancelled']]])->json('data.items'))->pluck('title')->all());
        $this->assertSame(2, $this->fetch('/api/v1/meetings', ['page' => 1, 'per_page' => 10, 'search' => 'zawadi'])->json('data.pagination.total'));

        // no params: the old list, every meeting (the calendar reads it)
        $this->assertCount(17, $this->fetch('/api/v1/meetings')->json('meetings'));
        $this->assertCount(15, $this->fetch('/api/v1/meetings', [], $guests[1])->json('meetings')); // an invitee sees their own invitations only
    }

    public function test_business_recommendations_page_filter_search_and_export_on_the_server(): void
    {
        for ($i = 0; $i < 18; $i++) {
            Listing::create(['name' => "Farm $i", 'category' => 'Agriculture', 'location' => 'Westlands, Nairobi, Kenya', 'investment_needed' => 5000]);
        }
        for ($i = 0; $i < 5; $i++) {
            Listing::create(['name' => "Solar $i", 'category' => 'Energy', 'location' => 'Kisumu, Kenya', 'investment_needed' => 200000]);
        }

        $page = $this->fetch('/api/v1/ai/investor/listings', ['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(23, $page->json('data.pagination.total'));
        $this->assertSame(23, $page->json('data.stats.total'));
        $this->assertEquals(['Agriculture' => 18, 'Energy' => 5], collect($page->json('data.facets.sector'))->pluck('count', 'value')->all());
        $this->assertEquals(['Nairobi, Kenya' => 18, 'Kisumu, Kenya' => 5], collect($page->json('data.facets.location'))->pluck('count', 'value')->all());

        $this->assertCount(23, $this->fetch('/api/v1/ai/investor/listings', ['export' => 1, 'per_page' => 10])->json('data.items'));
        $this->assertCount(5, $this->fetch('/api/v1/ai/investor/listings', ['export' => 1, 'filters' => ['goal' => ['over-100k']]])->json('data.items'));
        $this->assertCount(5, $this->fetch('/api/v1/ai/investor/listings', ['export' => 1, 'filters' => ['sector' => ['Energy']]])->json('data.items'));
        $this->assertSame(['Solar 3'], collect($this->fetch('/api/v1/ai/investor/listings', ['page' => 1, 'per_page' => 10, 'search' => 'solar 3'])->json('data.items'))->pluck('listing.name')->all());

        $this->assertCount(23, $this->fetch('/api/v1/ai/investor/listings')->json('scores'));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/ai/investor/listings?page=1')->assertUnauthorized();
    }
}
