<?php

namespace Tests\Feature\Shared;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Communication\Meeting;
use App\Models\Finance\Transactions;
use App\Models\Misc\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The declarative filters (filter_schema) of the shared lists: My events, meetings, business matches and transactions. */
class SharedListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create(['user_type_id' => 1, 'first_name' => 'Mina', 'last_name' => 'Me']);
    }

    private function fetch(string $url, array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->me, 'sanctum')->getJson($url.($query ? '?'.http_build_query($query) : ''));
    }

    /** How many rows an export with these filters gives. */
    private function rowCount(string $url, array $filters, array $extra = []): int
    {
        return count($this->fetch($url, ['export' => 1, 'filters' => $filters] + $extra)->assertOk()->json('data.items'));
    }

    private function schema(string $url, array $query = [])
    {
        return collect($this->fetch($url, $query + ['page' => 1, 'per_page' => 10])->assertOk()->json('data.filter_schema'))->keyBy('key');
    }

    // ── My events ────────────────────────────────────────────────────────────────────────────────

    private function event(array $attributes = []): Event
    {
        return Event::create($attributes + [
            'user_id' => $this->me->id, 'event_name' => 'Summit', 'event_type' => 'conference', 'sector' => 'Agriculture',
            'start_date' => now()->addDays(10)->toDateTimeString(), 'end_date' => now()->addDays(11)->toDateTimeString(),
            'timezone' => 'Africa/Nairobi', 'location_type' => 'in_person', 'city' => 'Nairobi', 'country' => 'Kenya',
            'description' => 'About', 'cost_type' => 'paid', 'price' => 500, 'currency' => 'KES', 'cover_image' => 'events/cover.jpg', 'active' => 1,
        ]);
    }

    public function test_my_events_describe_and_apply_every_filter(): void
    {
        $this->event(['ticket_link' => 'https://tickets.example.test']);                                      // upcoming, paid 500
        $this->event(['price' => 1500, 'city' => 'Mombasa', 'event_type' => 'workshop']);                       // upcoming, paid 1500
        $this->event(['location_type' => 'virtual', 'cost_type' => 'free', 'price' => null, 'active' => 0, 'city' => null, 'country' => null,
            'start_date' => now()->subDays(5)->toDateTimeString(), 'end_date' => now()->subDays(4)->toDateTimeString()]); // ended, free
        $this->event(['user_id' => User::factory()->create()->id, 'city' => 'Kisumu']); // someone else's never counts

        $schema = $this->schema('/api/v1/events');
        $this->assertSame(['status', 'timing', 'type', 'sector', 'format', 'cost', 'country', 'city', 'price', 'date', 'created', 'tickets'], $schema->keys()->all());
        $this->assertEquals(['upcoming' => 2, 'live' => 0, 'ended' => 1], collect($schema['timing']['options'])->pluck('count', 'value')->all());
        $this->assertSame('shared:events.browseEvents.status.comingSoon', $schema['timing']['options'][0]['label']);
        $this->assertEquals(['active' => 2, 'inactive' => 1], collect($schema['status']['options'])->pluck('count', 'value')->all());
        $this->assertEquals(['free' => 1, 'paid' => 2], collect($schema['cost']['options'])->pluck('count', 'value')->all());
        $this->assertEquals(['Nairobi' => 1, 'Mombasa' => 1], collect($schema['city']['options'])->pluck('count', 'value')->all());
        $this->assertTrue($schema['city']['searchable']);
        $this->assertSame([500, 1500], [$schema['price']['min'], $schema['price']['max']]);
        $this->assertContains('next7Days', $schema['date']['presets']);
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 2]], $schema['tickets']['options']);

        $url = '/api/v1/events';
        $this->assertSame(1, $this->rowCount($url, ['timing' => ['ended']]));
        $this->assertSame(1, $this->rowCount($url, ['format' => ['virtual']]));
        $this->assertSame(1, $this->rowCount($url, ['type' => ['workshop']]));
        $this->assertSame(1, $this->rowCount($url, ['city' => ['Mombasa']]));
        $this->assertSame(2, $this->rowCount($url, ['country' => ['Kenya']]));
        $this->assertSame(1, $this->rowCount($url, ['price' => '1000~']));
        $this->assertSame(2, $this->rowCount($url, ['price' => '~1500', 'cost' => ['paid']]));
        $this->assertSame(1, $this->rowCount($url, ['tickets' => 'yes']));
        $this->assertSame(2, $this->rowCount($url, ['date' => now()->toDateString().'~'.now()->addDays(30)->toDateString()]));
        $this->assertSame(3, $this->rowCount($url, ['created' => now()->toDateString().'~']));
        $this->assertSame(0, $this->rowCount($url, ['created' => '~2001-01-01']));
        // the other filters narrow a filter's own counts
        $this->assertEquals(['upcoming' => 0, 'live' => 0, 'ended' => 1], collect($this->schema($url, ['filters' => ['cost' => ['free']]])['timing']['options'])->pluck('count', 'value')->all());
        $this->assertSame([], $this->fetch($url, ['export' => 1])->json('data.filter_schema'));
    }

    // ── Meetings ─────────────────────────────────────────────────────────────────────────────────

    private function meeting(User $host, User $client, array $attributes = []): Meeting
    {
        return Meeting::create($attributes + [
            'host_id' => $host->id, 'client_id' => $client->id,
            'host_name' => trim($host->first_name.' '.$host->last_name), 'client_name' => trim($client->first_name.' '.$client->last_name),
            'date' => now()->addDays(3)->toDateString(), 'time' => '10:00',
            'title' => 'Catch up', 'description' => null, 'link' => 'https://meet.example.test/x', 'status' => 'pending',
        ]);
    }

    public function test_meetings_describe_and_apply_every_filter(): void
    {
        $guest = User::factory()->create(['first_name' => 'Gina', 'last_name' => 'Guest']);
        $host = User::factory()->create(['first_name' => 'Zawadi', 'last_name' => 'Host']);
        $this->meeting($this->me, $guest, ['title' => 'I host', 'link' => '']);                                    // host, upcoming, no link
        $this->meeting($host, $this->me, ['title' => 'Accepted one', 'status' => 'accepted', 'date' => now()->addDays(20)->toDateString()]);
        $this->meeting($host, $this->me, ['title' => 'Waiting one']);                                                // invited, pending
        $this->meeting($host, $this->me, ['title' => 'Old one', 'date' => now()->subDays(3)->toDateString()]);       // invited, past
        $this->meeting($host, $this->me, ['title' => 'Said no', 'status' => 'declined']);                            // cancelled bucket

        $url = '/api/v1/meetings';
        $schema = $this->schema($url);
        $this->assertSame(['role', 'response', 'date', 'created', 'video'], $schema->keys()->all());
        $this->assertEquals(['host' => 1, 'invited' => 4], collect($schema['role']['options'])->pluck('count', 'value')->all());
        $this->assertEquals(['pending' => 2, 'accepted' => 1, 'declined' => 1, 'cancelled' => 0], collect($schema['response']['options'])->pluck('count', 'value')->all());
        $this->assertSame([['value' => 'yes', 'count' => 4], ['value' => 'no', 'count' => 1]], $schema['video']['options']);
        $this->assertSame('app:listFilterMeetingDate', $schema['date']['label']);

        $titles = fn (array $filters, array $extra = []) => collect($this->fetch($url, ['export' => 1, 'filters' => $filters] + $extra)->json('data.items'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['I host'], $titles(['role' => ['host']]));
        $this->assertSame(['Accepted one'], $titles(['response' => ['accepted']]));
        $this->assertSame(['I host'], $titles(['video' => 'no']));
        $this->assertSame(['Accepted one'], $titles(['date' => now()->addDays(10)->toDateString().'~']));
        $this->assertSame(['Old one'], $titles(['date' => '~'.now()->subDay()->toDateString()]));
        $this->assertCount(5, $titles(['created' => now()->toDateString().'~'.now()->toDateString()]));
        $this->assertSame([], $titles(['created' => '~2001-01-01']));
        // ?bucket= (the buttons above the table) narrows the rows and the panel's counts; filters stay free
        $this->assertSame(['Waiting one'], $titles(['response' => ['pending']], ['bucket' => 'upcoming']));
        $this->assertEquals(['host' => 1, 'invited' => 2], collect($this->schema($url, ['bucket' => 'upcoming'])['role']['options'])->pluck('count', 'value')->all());
        $this->assertSame(['Said no'], $titles(['bucket' => ['cancelled']])); // the older filters[bucket] still works
        $this->assertSame(5, $this->fetch($url, ['page' => 1, 'per_page' => 10, 'bucket' => 'upcoming'])->json('data.stats.all')); // the buttons count everything
    }

    // ── Business matches ─────────────────────────────────────────────────────────────────────────

    public function test_business_matches_describe_and_apply_every_filter(): void
    {
        $farm = Listing::create(['name' => 'Farm', 'category' => 'Agriculture', 'location' => 'Westlands, Nairobi, Kenya', 'investment_needed' => 5000, 'invest_count' => 2, 'amount_collected' => 1000, 'stage' => 'Seed']);
        Listing::create(['name' => 'Solar', 'category' => 'Energy', 'location' => 'Kisumu, Kenya', 'investment_needed' => 200000, 'invest_count' => 9, 'amount_collected' => 50000, 'stage' => 'Growth']);
        Listing::create(['name' => 'Water', 'category' => 'Energy', 'location' => 'Kisumu, Kenya', 'investment_needed' => 20000, 'stage' => 'Not specified']);
        \App\Models\Shared\Like::create(['user_id' => $this->me->id, 'type' => 'listing', 'listing_id' => $farm->id]);

        $url = '/api/v1/ai/investor/listings';
        $schema = $this->schema($url);
        $this->assertSame(['sector', 'goal', 'location', 'stage', 'score', 'amount', 'raised', 'investors', 'liked', 'listed'], $schema->keys()->all());
        $this->assertSame(['under-1k', '1k-10k', '10k-50k', '50k-100k', 'over-100k'], collect($schema['goal']['options'])->pluck('value')->all());
        $this->assertEquals(['Seed' => 1, 'Growth' => 1], collect($schema['stage']['options'])->pluck('count', 'value')->all());
        $this->assertSame([5000, 200000], [$schema['amount']['min'], $schema['amount']['max']]);
        $this->assertSame([0, 9], [$schema['investors']['min'], $schema['investors']['max']]);
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 2]], $schema['liked']['options']);
        $this->assertTrue($schema['location']['searchable']);
        $this->assertSame('numberRange', $schema['score']['type']);

        $names = fn (array $filters) => collect($this->fetch($url, ['export' => 1, 'filters' => $filters])->json('data.items'))->pluck('listing.name')->sort()->values()->all();
        $this->assertSame(['Solar', 'Water'], $names(['sector' => ['Energy']]));
        $this->assertSame(['Water'], $names(['goal' => ['10k-50k']]));
        $this->assertSame(['Growth'], collect($this->fetch($url, ['export' => 1, 'filters' => ['stage' => ['Growth']]])->json('data.items'))->pluck('listing.stage')->all());
        $this->assertSame(['Solar', 'Water'], $names(['amount' => '10000~']));
        $this->assertSame(['Solar'], $names(['raised' => '10000~']));
        $this->assertSame(['Farm', 'Solar'], $names(['investors' => '1~']));
        $this->assertSame(['Farm'], $names(['liked' => 'yes']));
        $this->assertSame(['Farm', 'Solar', 'Water'], $names(['listed' => now()->toDateString().'~', 'score' => '0~']));
        $this->assertSame([], $names(['score' => '1000~']));
    }

    // ── Transactions ─────────────────────────────────────────────────────────────────────────────

    private function tx(array $o): Transactions
    {
        return Transactions::create($o + ['user_id' => $this->me->id, 'type' => 'deposit', 'method' => 'lipr', 'gross_amount' => 100, 'fee_amount' => 0, 'net_amount' => 100, 'status' => 'settled', 'direction' => 'credit']);
    }

    public function test_transactions_describe_and_apply_every_filter(): void
    {
        $other = User::factory()->create(['user_type_id' => 1]);
        $this->tx(['net_amount' => 500, 'gross_amount' => 520, 'fee_amount' => 20]);
        $this->tx(['type' => 'withdraw', 'direction' => 'debit', 'net_amount' => 200, 'gross_amount' => 200, 'method' => 'bank_transfer']);
        $this->tx(['user_id' => $other->id, 'recipient_id' => $this->me->id, 'type' => 'program_milestone', 'direction' => 'debit', 'net_amount' => 300, 'gross_amount' => 300]); // money in for me
        $this->tx(['method' => 'stripe_card', 'status' => 'failed', 'net_amount' => 0, 'gross_amount' => 40]);  // USD; amount = gross when net is empty
        $this->tx(['user_id' => $other->id]); // not mine

        $url = '/api/v1/transactions';
        $schema = $this->schema($url);
        $this->assertSame(['direction', 'type', 'status', 'method', 'currency', 'amount', 'fee', 'date'], $schema->keys()->all());
        $this->assertSame([
            ['value' => 'credit', 'count' => 3, 'label' => 'shared:wallet.transactionsList.moneyIn'],
            ['value' => 'debit', 'count' => 1, 'label' => 'shared:wallet.transactionsList.moneyOut'],
        ], $schema['direction']['options']);
        $this->assertEquals(['KES' => 3, 'USD' => 1], collect($schema['currency']['options'])->pluck('count', 'value')->all());
        $this->assertSame([40, 500], [$schema['amount']['min'], $schema['amount']['max']]);
        $this->assertSame([0, 20], [$schema['fee']['min'], $schema['fee']['max']]);
        $this->assertSame('dateRange', $schema['date']['type']);
        // counts follow the other filters
        $this->assertEquals(['credit' => 0, 'debit' => 1], collect($this->schema($url, ['filters' => ['amount' => '150~250']])['direction']['options'])->pluck('count', 'value')->all());

        $this->assertSame(1, $this->rowCount($url, ['direction' => ['debit']]));
        $this->assertSame(1, $this->rowCount($url, ['currency' => ['USD']]));
        $this->assertSame(1, $this->rowCount($url, ['amount' => '~50']));       // the gross 40
        $this->assertSame(2, $this->rowCount($url, ['amount' => '250~']));      // 500 and 300
        $this->assertSame(1, $this->rowCount($url, ['fee' => '1~']));
        $this->assertSame(1, $this->rowCount($url, ['method' => ['bank_transfer'], 'type' => ['withdraw']]));
        $this->assertSame(1, $this->rowCount($url, ['status' => ['failed']]));
        $this->assertSame(4, $this->rowCount($url, ['date' => now()->toDateString().'~']));
    }
}
