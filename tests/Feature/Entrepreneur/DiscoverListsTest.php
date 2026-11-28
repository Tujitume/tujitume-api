<?php

namespace Tests\Feature\Entrepreneur;

use App\Models\Auth\User;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\StartupPitches;
use App\Models\Organizations\Organization;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\ProgramIndustry;
use App\Models\Shared\Like;
use App\Models\Shared\Watchlist;
use Tests\Feature\Programs\ProgramTestCase;

/**
 * Discover Programs (/programs/public-programs) and Explore Capital (/capital/capital-offers) as server lists: the
 * filters are described in `data.filter_schema`, each one narrows the rows, search / sort / export work on every match,
 * and a request without list parameters still gets the old response. Applicants never see a draft or hidden program.
 */
class DiscoverListsTest extends ProgramTestCase
{
    private function fetchList(string $url, array $query = [])
    {
        return $this->actingAs($this->applicantUser, 'sanctum')->getJson($url.($query ? '?'.http_build_query($query) : ''))->assertOk();
    }

    private function pick(string $url, array $filters, string $field, array $extra = []): array
    {
        $items = collect($this->fetchList($url, $extra + ['export' => 1, 'filters' => $filters])->json('data.items'))->pluck($field)->all();
        sort($items);

        return $items;
    }

    private function schema(string $url, array $extra = []): array
    {
        return collect($this->fetchList($url, $extra + ['page' => 1, 'per_page' => 5])->json('data.filter_schema'))->keyBy('key')->all();
    }

    private static function counts(array $entry): array
    {
        return collect($entry['options'])->pluck('count', 'value')->all();
    }

    private function program(array $attributes, array $round = []): Program
    {
        $program = Program::factory()->create($attributes + ['user_id' => $this->orgUser->id, 'organization_id' => $this->organization->id, 'status' => 'published', 'visible' => 1]);
        ProgramRound::factory()->create($round + ['program_id' => $program->id, 'round_number' => 1, 'status' => 'published']);

        return $program;
    }

    public function test_discover_programs_describe_and_apply_every_filter(): void
    {
        $energy = ProgramIndustry::create(['name' => 'Energy']);
        $sunrise = Organization::factory()->create(['owner_user_id' => $this->orgUser->id, 'name' => 'Sunrise Foundation', 'display_name' => 'Sunrise Foundation']);
        $this->program->update(['program_title' => 'Alpha Grant', 'organization_id' => $this->organization->id]);
        $soon = $this->program([
            'program_title' => 'Solar Boost', 'program_type' => 'multi_round', 'total_rounds' => 2, 'organization_id' => $sunrise->id,
            'program_industry_id' => $energy->id, 'funding_per_business' => 50000, 'total_program_amount' => 500000,
            'require_kyc_verification_to_apply' => true, 'use_match_score' => false, 'application_deadline' => now()->addDays(3)->toDateString(),
            'cover_image' => 'https://cdn.example.test/cover.jpg', 'intro_video_url' => 'https://youtu.be/abc',
        ]);
        $later = $this->program(['program_title' => 'Later Fund', 'application_start_date' => now()->addDays(10)->toDateString(), 'application_deadline' => now()->addDays(40)->toDateString()],
            ['opens_at' => now()->addDays(10), 'closes_at' => now()->addDays(40)]);
        $done = $this->program(['program_title' => 'Done Fund', 'status' => 'open'], ['status' => 'closed']);
        $this->program(['program_title' => 'Draft Fund', 'status' => 'draft']);
        $this->program(['program_title' => 'Hidden Fund', 'visible' => 0]);
        Like::create(['user_id' => $this->applicantUser->id, 'listing_id' => $soon->id, 'type' => 'program']);
        Watchlist::create(['user_id' => $this->applicantUser->id, 'org_id' => $later->id, 'org_type' => 'program']);
        ProgramApplication::factory()->count(2)->create(['program_id' => $done->id, 'program_owner_id' => $this->orgUser->id]);
        ProgramApplication::factory()->create(['program_id' => $later->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'draft']);

        $url = '/api/v1/programs/public-programs';
        $title = fn (array $filters, array $extra = []) => $this->pick($url, $filters, 'program_title', $extra);
        $schema = $this->schema($url);
        $this->assertSame(['availability', 'industry', 'organization', 'type', 'funding', 'total', 'matchScore', 'kyc', 'deadline', 'opens', 'liked', 'saved', 'applied', 'hasCover', 'hasVideo'], array_keys($schema));
        $this->assertSame(['open' => 2, 'closingSoon' => 1, 'upcoming' => 1, 'closed' => 1], self::counts($schema['availability']));
        $this->assertSame('app:entFilterOpenNow', $schema['availability']['options'][0]['label']);
        $this->assertSame('Energy', collect($schema['industry']['options'])->firstWhere('value', (string) $energy->id)['label']);
        $this->assertSame('Sunrise Foundation', collect($schema['organization']['options'])->firstWhere('value', (string) $sunrise->id)['label']);
        $this->assertSame(['single' => 3, 'multi' => 1], self::counts($schema['type']));
        $this->assertSame([10000, 50000], [$schema['funding']['min'], $schema['funding']['max']]);
        $this->assertContains('next7Days', $schema['deadline']['presets']);
        $this->assertSame(['yes' => 1, 'no' => 3], self::counts($schema['liked']));

        // drafts and hidden programs are never listed, whatever is asked
        $this->assertSame(['Alpha Grant', 'Done Fund', 'Later Fund', 'Solar Boost'], $title([]));
        $this->assertSame([], $title(['availability' => ['closed', 'open', 'upcoming', 'closingSoon'], 'organization' => [(string) $this->organization->id], 'hasCover' => 'no', 'type' => ['single']], ['search' => 'Draft']));

        $this->assertSame(['Alpha Grant', 'Solar Boost'], $title(['availability' => ['open']]));
        $this->assertSame(['Solar Boost'], $title(['availability' => ['closingSoon']]));
        $this->assertSame(['Later Fund'], $title(['availability' => ['upcoming']]));
        $this->assertSame(['Done Fund'], $title(['availability' => ['closed']]));
        $this->assertSame(['Solar Boost'], $title(['industry' => [(string) $energy->id]]));
        $this->assertSame(['Alpha Grant', 'Done Fund', 'Later Fund'], $title(['organization' => [(string) $this->organization->id]]));
        $this->assertSame(['Solar Boost'], $title(['type' => ['multi']]));
        $this->assertSame(['Solar Boost'], $title(['funding' => '20000~']));
        $this->assertSame(['Alpha Grant', 'Done Fund', 'Later Fund'], $title(['total' => '~200000']));
        $this->assertSame(['Solar Boost'], $title(['matchScore' => 'no']));
        $this->assertSame(['Solar Boost'], $title(['kyc' => 'yes']));
        $this->assertSame(['Solar Boost'], $title(['deadline' => now()->toDateString().'~'.now()->addDays(7)->toDateString()]));
        $this->assertSame(['Later Fund'], $title(['opens' => now()->addDays(5)->toDateString().'~']));
        $this->assertSame(['Solar Boost'], $title(['liked' => 'yes']));
        $this->assertSame(['Later Fund'], $title(['saved' => 'yes']));
        $this->assertSame(['Alpha Grant'], $title(['applied' => 'yes'])); // the draft on Later Fund is not an application yet
        $this->assertSame(['Solar Boost'], $title(['hasCover' => 'yes']));
        $this->assertSame(['Solar Boost'], $title(['hasVideo' => 'yes']));
        // counts follow the other filters
        $this->assertSame(['open' => 1, 'closingSoon' => 0, 'upcoming' => 1, 'closed' => 1], self::counts($this->schema($url, ['filters' => ['type' => ['single']]])['availability']));

        // search: title, description, industry, organization
        $this->assertSame(['Solar Boost'], $title([], ['search' => 'energy']));
        $this->assertSame(['Solar Boost'], $title([], ['search' => 'sunrise']));
        $this->assertSame(['Later Fund'], $title([], ['search' => 'later']));

        // sort, and pages
        $first = fn (string $sort) => $this->fetchList($url, ['page' => 1, 'per_page' => 1, 'sort' => $sort])->json('data.items.0.program_title');
        $this->assertSame('Done Fund', $first('-applied'));
        $this->assertSame('Solar Boost', $first('deadline'));
        $this->assertSame('Solar Boost', $first('-amount'));
        $this->assertSame('Alpha Grant', $first('title'));
        $page = $this->fetchList($url, ['page' => 2, 'per_page' => 1])->json('data');
        $this->assertSame(4, $page['pagination']['total']);
        $this->assertCount(1, $page['items']);
        $this->assertSame(['open' => 2, 'closing_soon' => 1, 'upcoming' => 1], collect($page['stats'])->only(['open', 'closing_soon', 'upcoming'])->all());

        // the row carries what the old list sent, plus availability
        $row = collect($this->fetchList($url, ['page' => 1, 'per_page' => 10, 'filters' => ['id' => [$soon->id]]])->json('data.items'))->first();
        $this->assertSame(['closingSoon', true, 'published', 0], [$row['availability'], $row['liked'], $row['status']['value'], $row['pitch_count']]);
        $this->assertSame('https://cdn.example.test/cover.jpg', $row['cover_image_url']);

        // an export is every match (beyond one page) and carries no schema
        $export = $this->fetchList($url, ['export' => 1, 'per_page' => 1])->json('data');
        $this->assertCount(4, $export['items']);
        $this->assertSame([], $export['filter_schema']);

        // without list parameters: the old response (every listed program, no schema)
        $old = $this->fetchList($url)->json();
        $this->assertCount(4, $old['programs']);
        $this->assertArrayNotHasKey('filter_schema', $old['data']);

        // the endpoint is for signed-in people only, as before
        $this->app['auth']->forgetGuards();
        $this->getJson($url.'?page=1')->assertUnauthorized();
    }

    public function test_explore_capital_describes_and_applies_every_filter(): void
    {
        $fundA = User::factory()->create(['user_type_id' => 5, 'first_name' => 'Acacia', 'last_name' => 'Fund']);
        $fundB = User::factory()->create(['user_type_id' => 5, 'first_name' => 'Baobab', 'last_name' => 'Capital']);
        $offer = fn (User $owner, array $a) => CapitalOffer::create($a + ['user_id' => $owner->id, 'total_capital_available' => 100000, 'per_startup_allocation' => 1000, 'sectors' => ['Agriculture'], 'regions' => ['Kenya'], 'startup_stage' => ['seed'], 'visible' => 1, 'start_date' => '2026-01-01', 'end_date' => '2030-12-31']);
        $open = $offer($fundA, ['offer_title' => 'Open Fund', 'sectors' => ['Agriculture', 'Energy']]);
        $soon = $offer($fundB, ['offer_title' => 'Soon Fund', 'sectors' => ['Energy'], 'regions' => ['Uganda'], 'startup_stage' => ['growth'], 'per_startup_allocation' => 5000, 'total_capital_available' => 50000, 'end_date' => now()->addDays(5)->toDateString()]);
        $offer($fundA, ['offer_title' => 'Later Fund', 'start_date' => now()->addDays(20)->toDateString()]);
        $offer($fundB, ['offer_title' => 'Closed Fund', 'sectors' => ['Health'], 'end_date' => now()->subDays(5)->toDateString()]);
        $offer($fundA, ['offer_title' => 'Hidden Fund', 'visible' => 0]);
        StartupPitches::create(['capital_id' => $open->id, 'user_id' => $this->applicantUser->id, 'capital_owner_id' => $fundA->id, 'startup_name' => 'Mine', 'contact_person_name' => 'Me', 'contact_person_email' => 'me@example.test', 'sector' => 'Agriculture', 'headquarters_location' => 'Nairobi', 'stage' => 'seed', 'burn_rate' => 0, 'total_amount_requested' => 500, 'status' => StartupPitches::STATUS_PENDING]);
        Watchlist::create(['user_id' => $this->applicantUser->id, 'org_id' => $soon->id, 'org_type' => 'capital']);

        $url = '/api/v1/capital/capital-offers';
        $title = fn (array $filters, array $extra = []) => $this->pick($url, $filters, 'offer_title', $extra);
        $schema = $this->schema($url);
        $this->assertSame(['timing', 'sector', 'region', 'stage', 'provider', 'allocation', 'pool', 'applied', 'saved', 'ends', 'starts'], array_keys($schema));
        $this->assertSame(['open' => 1, 'closingSoon' => 1, 'upcoming' => 1, 'closed' => 1], self::counts($schema['timing']));
        $this->assertEquals(['Agriculture' => 2, 'Energy' => 2, 'Health' => 1], self::counts($schema['sector']));
        $this->assertSame('Baobab Capital', collect($schema['provider']['options'])->firstWhere('value', (string) $fundB->id)['label']);
        $this->assertSame([1000, 5000], [$schema['allocation']['min'], $schema['allocation']['max']]);
        $this->assertContains('next30Days', $schema['ends']['presets']);

        $this->assertSame(['Closed Fund', 'Later Fund', 'Open Fund', 'Soon Fund'], $title([])); // hidden offers are never listed
        $this->assertSame(['Soon Fund'], $title(['timing' => ['closingSoon']]));
        $this->assertSame(['Later Fund'], $title(['timing' => ['upcoming']]));
        $this->assertSame(['Open Fund', 'Soon Fund'], $title(['sector' => ['Energy']]));
        $this->assertSame(['Soon Fund'], $title(['region' => ['Uganda']]));
        $this->assertSame(['Soon Fund'], $title(['stage' => ['growth']]));
        $this->assertSame(['Closed Fund', 'Soon Fund'], $title(['provider' => [(string) $fundB->id]]));
        $this->assertSame(['Soon Fund'], $title(['allocation' => '2000~']));
        $this->assertSame(['Soon Fund'], $title(['pool' => '~60000']));
        $this->assertSame(['Open Fund'], $title(['applied' => 'yes']));
        $this->assertSame(['Soon Fund'], $title(['saved' => 'yes']));
        $this->assertSame(['Soon Fund'], $title(['ends' => now()->toDateString().'~'.now()->addDays(29)->toDateString()]));
        $this->assertSame(['Later Fund'], $title(['starts' => now()->toDateString().'~']));
        $this->assertSame(['Soon Fund'], $title([], ['search' => 'uganda']));
        $this->assertSame(['Open Fund', 'Soon Fund'], $title([], ['search' => 'energy']));

        $this->assertSame('Soon Fund', $this->fetchList($url, ['page' => 1, 'per_page' => 1, 'sort' => '-allocation'])->json('data.items.0.offer_title'));
        $this->assertSame('Closed Fund', $this->fetchList($url, ['page' => 1, 'per_page' => 1, 'sort' => 'title'])->json('data.items.0.offer_title'));
        $page = $this->fetchList($url, ['page' => 1, 'per_page' => 2])->json('data');
        $this->assertSame(4, $page['pagination']['total']);
        $this->assertSame([4, 2, 1], [$page['stats']['total'], $page['stats']['open'], $page['stats']['saved']]);
        $row = collect($this->fetchList($url, ['export' => 1, 'filters' => ['id' => [$open->id]]])->json('data.items'))->first();
        $this->assertSame([true, 'pending', false], [$row['applied'], $row['application_status'], $row['saved']]);

        $this->assertCount(4, $this->fetchList($url, ['export' => 1, 'per_page' => 1])->json('data.items'));

        // without list parameters: the old response
        $old = $this->fetchList($url)->json();
        $this->assertArrayHasKey('capital', $old);
        $this->assertArrayNotHasKey('data', $old);
    }
}
