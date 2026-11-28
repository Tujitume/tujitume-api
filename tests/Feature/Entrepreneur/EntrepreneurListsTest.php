<?php

namespace Tests\Feature\Entrepreneur;

use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\StartupPitches;
use App\Models\Milestones\Milestones;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use Tests\Feature\Programs\ProgramTestCase;

/**
 * The business owner's tables (My Businesses, Deal Room, Milestones, Investment Bids, Capital and Program applications)
 * page, search, filter, sort and export on the server; without list params each endpoint answers as it did before.
 */
class EntrepreneurListsTest extends ProgramTestCase
{
    private function list(string $url, array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->applicantUser, 'sanctum')->getJson($url.($query ? '?'.http_build_query($query) : ''));
    }

    private function listing(array $attributes = [], ?User $owner = null): Listing
    {
        return Listing::create($attributes + [
            'user_id' => ($owner ?? $this->applicantUser)->id,
            'name' => 'Biz '.uniqid(),
            'category' => 'Agriculture',
            'stage' => 'seed',
            'location' => 'Westlands, Nairobi, Kenya',
            'investment_needed' => 1000,
            'amount_collected' => 0,
            'active' => 1,
            'threshold_met' => 0,
        ]);
    }

    public function test_my_businesses_page_filter_search_and_export_on_the_server(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->listing(['amount_collected' => $i * 10]);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->listing(['category' => 'Technology', 'active' => 0, 'location' => 'Kisumu, Kenya']);
        }
        $this->listing(['name' => 'Zawadi Farms', 'threshold_met' => 1, 'amount_collected' => 1000]);
        $this->listing(['active' => null, 'name' => 'Never Activated']);
        $this->listing([], User::factory()->create(['user_type_id' => 1])); // someone else's never shows

        $url = '/api/v1/business/listings';
        $page = $this->list($url, ['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(23, $page->json('data.pagination.total'));
        $this->assertSame(23, $page->json('data.stats.total'));
        $this->assertSame(16, $page->json('data.stats.active'));
        $this->assertEquals(['Agriculture' => 17, 'Technology' => 6], collect($page->json('data.facets.industry'))->pluck('count', 'value')->all());
        $this->assertEquals(['Nairobi, Kenya' => 17, 'Kisumu, Kenya' => 6], collect($page->json('data.facets.location'))->pluck('count', 'value')->all());

        $all = $this->list($url, ['export' => 1, 'per_page' => 10])->json('data.items');
        $this->assertCount(23, $all);

        $this->assertCount(7, $this->list($url, ['export' => 1, 'filters' => ['status' => ['inactive']]])->json('data.items'));
        $this->assertCount(7, $this->list($url, ['export' => 1, 'filters' => ['deal' => ['hidden']]])->json('data.items'));
        $met = $this->list($url, ['export' => 1, 'filters' => ['deal' => ['targetMet']]])->json('data.items');
        $this->assertSame(['Zawadi Farms'], collect($met)->pluck('name')->all());
        $this->assertSame('targetMet', $met[0]['deal']);
        $this->assertCount(6, $this->list($url, ['export' => 1, 'filters' => ['location' => ['Kisumu, Kenya']]])->json('data.items'));
        $this->assertSame(['Zawadi Farms'], collect($this->list($url, ['page' => 1, 'per_page' => 5, 'search' => 'zawadi'])->json('data.items'))->pluck('name')->all());
        $this->assertSame('Zawadi Farms', $this->list($url, ['page' => 1, 'per_page' => 5, 'sort' => '-funded'])->json('data.items.0.name'));

        // no params: the full list under the old key (the store, the dashboard and the forms read it)
        $this->assertCount(23, $this->list($url)->assertOk()->json('business'));
        $this->assertNull($this->list($url)->json('data'));
    }

    public function test_the_milestones_table_summarises_each_business_on_the_server(): void
    {
        $full = $this->listing(['name' => 'Fully Planned']);
        $partial = $this->listing(['name' => 'Half Planned']);
        for ($i = 0; $i < 12; $i++) {
            $this->listing(['name' => "Empty $i"]);
        }
        $milestone = fn (Listing $l, array $a) => Milestones::create($a + ['user_id' => $this->applicantUser->id, 'listing_id' => $l->id, 'title' => 'M', 'amount' => 500, 'status' => 'to_do']);
        $milestone($full, ['title' => 'Build', 'status' => 'done', 'start_date' => '2026-01-01']);
        $milestone($full, ['title' => 'Launch', 'status' => 'in_progress', 'start_date' => '2026-02-01']);
        $milestone($partial, ['title' => 'Prototype', 'amount' => 200]);

        $url = '/api/v1/business/add_milestones';
        $page = $this->list($url, ['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(14, $page->json('data.pagination.total'));
        $this->assertEquals(['none' => 12, 'full' => 1, 'partial' => 1], collect($page->json('data.facets.plan'))->pluck('count', 'value')->all());
        $this->assertCount(14, $this->list($url, ['export' => 1, 'per_page' => 5])->json('data.items'));

        $row = $this->list($url, ['export' => 1, 'filters' => ['plan' => ['full']]])->json('data.items');
        $this->assertCount(1, $row);
        $this->assertSame(['Fully Planned', 2, 1, 'Launch', 'in_progress'], [$row[0]['name'], $row[0]['total'], $row[0]['done'], $row[0]['current']['title'], $row[0]['current_status']]);
        $this->assertSame(['Half Planned'], collect($this->list($url, ['page' => 1, 'per_page' => 5, 'search' => 'prototype'])->json('data.items'))->pluck('name')->all());

        // no params: the add-milestone form's data as before
        $old = $this->list($url)->assertOk();
        $this->assertCount(14, $old->json('business'));
        $this->assertCount(3, $old->json('milestones'));
    }

    public function test_investment_bids_page_per_tab_with_filters_and_tab_counts(): void
    {
        $farm = $this->listing(['name' => 'Farm', 'threshold_met' => 1]);
        $shop = $this->listing(['name' => 'Shop']);
        Milestones::create(['user_id' => $this->applicantUser->id, 'listing_id' => $farm->id, 'title' => 'Irrigation', 'amount' => 500, 'status' => 'to_do', 'active' => 1]);
        $investor = User::factory()->create(['user_type_id' => 2, 'first_name' => 'Amina', 'last_name' => 'Otieno']);
        $other = User::factory()->create(['user_type_id' => 2, 'first_name' => 'Brian', 'last_name' => 'Kamau']);
        $bid = fn (string $model, Listing $l, User $inv, array $a = []) => $model::create($a + ['owner_id' => $this->applicantUser->id, 'business_id' => $l->id, 'investor_id' => $inv->id, 'type' => 'Monetary', 'amount' => 100]);
        for ($i = 0; $i < 12; $i++) {
            $bid(BusinessBids::class, $farm, $investor);
        }
        $bid(BusinessBids::class, $shop, $other, ['type' => 'Asset']);
        $bid(BusinessBids::class, $shop, $other, ['type' => 'Asset']);
        for ($i = 0; $i < 4; $i++) {
            $bid(AcceptedBids::class, $farm, $investor, ['status' => 'Confirmed']);
        }
        $bid(AcceptedBids::class, $shop, $other, ['status' => 'under_verification', 'type' => 'Asset']);
        // another owner's bid never shows
        $stranger = User::factory()->create(['user_type_id' => 1]);
        BusinessBids::create(['owner_id' => $stranger->id, 'business_id' => $this->listing([], $stranger)->id, 'investor_id' => $investor->id, 'type' => 'Monetary', 'amount' => 5]);

        $pending = '/api/v1/business/business_bids';
        $page = $this->list($pending, ['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(14, $page->json('data.pagination.total'));
        $this->assertSame(['pending' => 14, 'active' => 4, 'under_verification' => 1], $page->json('data.stats'));
        $this->assertSame('Irrigation', collect($page->json('data.items'))->firstWhere('business', 'Farm')['milestone']);
        $this->assertEquals(['cash' => 12, 'equipment' => 2], collect($page->json('data.facets.type'))->pluck('count', 'value')->all());

        $this->assertCount(14, $this->list($pending, ['export' => 1, 'per_page' => 5])->json('data.items'));
        $this->assertCount(2, $this->list($pending, ['export' => 1, 'filters' => ['type' => ['equipment']]])->json('data.items'));
        $this->assertCount(12, $this->list($pending, ['export' => 1, 'filters' => ['milestone' => ['Irrigation']]])->json('data.items'));
        $this->assertCount(2, $this->list($pending, ['export' => 1, 'search' => 'brian kamau'])->json('data.items'));
        $this->assertCount(2, $this->list($pending, ['export' => 1, 'filters' => ['business' => ['Shop']]])->json('data.items'));
        $this->assertSame('Brian Kamau', $this->list($pending, ['page' => 1, 'per_page' => 5, 'sort' => '-investor'])->json('data.items.0.investor'));

        $confirmed = '/api/v1/business/confirmed_bids';
        $this->assertSame(4, $this->list($confirmed, ['page' => 1, 'per_page' => 10, 'stage' => 'active'])->json('data.pagination.total'));
        $verify = $this->list($confirmed, ['page' => 1, 'per_page' => 10, 'stage' => 'under-verification'])->json('data.items');
        $this->assertSame(['Shop'], collect($verify)->pluck('business')->all());

        // no params: the old shapes
        $this->assertCount(14, $this->list($pending)->json('bids'));
        $old = $this->list($confirmed)->assertOk();
        $this->assertCount(4, $old->json('bids'));
        $this->assertCount(1, $old->json('underVerify'));
        // the stranger sees only their own bid
        $this->assertSame(1, $this->list($pending, ['page' => 1, 'per_page' => 10], $stranger)->json('data.pagination.total'));
    }

    public function test_deep_links_fetch_one_row_by_id_on_every_entrepreneur_list(): void
    {
        // a pop-up / highlight link whose row is not on the current page asks for it with filters[id][] (list.find)
        $listings = collect(range(1, 12))->map(fn () => $this->listing());
        $target = $listings->first();
        $investor = User::factory()->create(['user_type_id' => 2]);
        $bids = collect(range(1, 12))->map(fn () => BusinessBids::create(['owner_id' => $this->applicantUser->id, 'business_id' => $target->id, 'investor_id' => $investor->id, 'type' => 'Asset', 'amount' => 100]));
        $stranger = User::factory()->create(['user_type_id' => 1]);
        $theirs = $this->listing([], $stranger);

        $one = fn (string $url, $id, array $extra = [], ?User $as = null) => $this->list($url, $extra + ['filters' => ['id' => [$id]], 'per_page' => 1, 'page' => 1], $as)->assertOk()->json('data.items');

        foreach (['/api/v1/business/listings' => $target->id, '/api/v1/business/add_milestones' => $target->id, '/api/v1/business/business_bids' => $bids->first()->id] as $url => $id) {
            $items = $one($url, $id);
            $this->assertCount(1, $items, $url);
            $this->assertSame($id, $items[0]['id'], $url);
        }
        $this->assertSame($this->application->id, $one('/api/v1/programs/my-applications', $this->application->id)[0]['id']);

        // someone else's record is never found
        $this->assertCount(0, $one('/api/v1/business/listings', $theirs->id));
        $this->assertCount(0, $one('/api/v1/business/business_bids', $bids->first()->id, [], $stranger));
    }

    public function test_capital_applications_and_deal_room_page_on_the_server(): void
    {
        $capitalOwner = User::factory()->create(['user_type_id' => 5]);
        $offer = fn (array $a = []) => CapitalOffer::create($a + ['user_id' => $capitalOwner->id, 'offer_title' => 'Fund '.uniqid(), 'total_capital_available' => 100000, 'per_startup_allocation' => 1000, 'sectors' => ['Agriculture'], 'regions' => ['Kenya'], 'startup_stage' => ['seed'], 'visible' => 1, 'start_date' => '2026-01-01', 'end_date' => '2030-12-31']);
        $apply = fn (CapitalOffer $o, int $status = 0) => StartupPitches::create(['capital_id' => $o->id, 'user_id' => $this->applicantUser->id, 'capital_owner_id' => $capitalOwner->id, 'startup_name' => 'Mine', 'contact_person_name' => 'Me', 'contact_person_email' => 'me@example.test', 'sector' => 'Agriculture', 'headquarters_location' => 'Nairobi', 'stage' => 'seed', 'burn_rate' => 0, 'total_amount_requested' => 500, 'status' => $status]);
        for ($i = 0; $i < 9; $i++) {
            $apply($offer());
        }
        for ($i = 0; $i < 3; $i++) {
            $apply($offer(['sectors' => ['Energy', 'Agriculture'], 'offer_title' => "Green Fund $i"]), 1);
        }
        $apply($offer(['visible' => 0])); // hidden offers are not listed, as before
        $offer(); // not applied

        $url = '/api/v1/capital/my-applications';
        $page = $this->list($url, ['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(12, $page->json('data.pagination.total'));
        $this->assertSame(['total' => 12, 'pending' => 9, 'approved' => 3], array_intersect_key($page->json('data.stats'), array_flip(['total', 'pending', 'approved'])));
        $this->assertSame(1, $page->json('data.stats.open_to_apply'));
        $this->assertEquals(['Agriculture' => 12, 'Energy' => 3], collect($page->json('data.facets.sector'))->pluck('count', 'value')->all());

        $this->assertCount(12, $this->list($url, ['export' => 1, 'per_page' => 5])->json('data.items'));
        $this->assertCount(3, $this->list($url, ['export' => 1, 'filters' => ['status' => ['approved']]])->json('data.items'));
        $this->assertCount(3, $this->list($url, ['export' => 1, 'filters' => ['sector' => ['Energy']]])->json('data.items'));
        $this->assertCount(1, $this->list($url, ['export' => 1, 'search' => 'green fund 2'])->json('data.items'));

        $deals = $this->list($url, ['page' => 1, 'per_page' => 10, 'stage' => 'approved'])->assertOk();
        $this->assertSame(3, $deals->json('data.pagination.total'));
        $this->assertSame(3000.0, (float) $deals->json('data.stats.allocation_total'));
        $this->assertSame('approved', $deals->json('data.items.0.application_status'));

        // someone else's applications are never listed
        $this->assertSame(0, $this->list($url, ['page' => 1, 'per_page' => 10], User::factory()->create(['user_type_id' => 1]))->json('data.pagination.total'));
    }

    public function test_my_program_applications_page_filter_search_and_export(): void
    {
        $multi = Program::factory()->create(['user_id' => $this->orgUser->id, 'status' => 'published', 'visible' => 1, 'program_type' => 'multi_round', 'program_title' => 'Scale Up']);
        ProgramApplication::factory()->count(14)->create(['program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'rejected']);
        ProgramApplication::factory()->count(4)->create(['program_id' => $multi->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'approved', 'match_score' => 80]);
        ProgramApplication::factory()->create(['program_id' => $multi->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'draft', 'startup_name' => 'Kito Labs']);
        // plus the pending application from setUp: 20 of the applicant's own

        $url = '/api/v1/programs/my-applications';
        $page = $this->list($url, ['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(20, $page->json('data.pagination.total'));
        $this->assertSame(19, $page->json('data.stats.total')); // drafts are not counted
        $this->assertSame(4, $page->json('data.stats.accepted'));
        $this->assertEquals(['rejected' => 14, 'approved' => 4, 'pending' => 1, 'draft' => 1], collect($page->json('data.facets.status'))->pluck('count', 'value')->all());
        $this->assertArrayHasKey('value', $page->json('data.items.0.status'));

        $this->assertCount(20, $this->list($url, ['export' => 1, 'per_page' => 5])->json('data.items'));
        $this->assertCount(4, $this->list($url, ['export' => 1, 'filters' => ['status' => ['approved']]])->json('data.items'));
        $this->assertCount(5, $this->list($url, ['export' => 1, 'filters' => ['type' => ['multi']]])->json('data.items'));
        $this->assertCount(5, $this->list($url, ['export' => 1, 'filters' => ['program' => ['Scale Up']]])->json('data.items'));
        $this->assertSame(['Kito Labs'], collect($this->list($url, ['export' => 1, 'search' => 'kito'])->json('data.items'))->pluck('startup_name')->all());

        // no params: the old full list and summary
        $old = $this->list($url)->assertOk();
        $this->assertCount(20, $old->json('pitches'));
        $this->assertSame(19, $old->json('pitch_count'));
        // the organization still sees its programs' submitted applications (drafts never)
        $this->assertSame(19, $this->list($url, ['page' => 1, 'per_page' => 5], $this->orgUser)->json('data.pagination.total'));
    }

    public function test_awarded_applications_search_and_filter_on_the_server(): void
    {
        ProgramApplication::factory()->count(12)->create(['program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'awarded', 'headquarters_location' => 'Kilimani, Nairobi, Kenya', 'awarded_amount' => 500]);
        ProgramApplication::factory()->create(['program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'awarded', 'startup_name' => 'Pwani Fish', 'headquarters_location' => 'Mombasa, Kenya', 'funding_setup_status' => 'completed']);

        $url = '/api/v1/programs/my-applications/awarded';
        $page = $this->list($url, ['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(13, $page->json('data.pagination.total'));
        $this->assertSame(13, $page->json('applications.total')); // the old page keys stay
        $this->assertSame(1, $page->json('data.stats.setup.completed'));
        $this->assertEquals(['Nairobi, Kenya' => 12, 'Mombasa, Kenya' => 1], collect($page->json('data.facets.location'))->pluck('count', 'value')->all());

        $this->assertCount(13, $this->list($url, ['export' => 1, 'per_page' => 5])->json('data.items'));
        $this->assertCount(12, $this->list($url, ['export' => 1, 'filters' => ['setup' => ['not_started']]])->json('data.items'));
        $this->assertCount(1, $this->list($url, ['export' => 1, 'filters' => ['location' => ['Mombasa, Kenya']]])->json('data.items'));
        $this->assertSame(['Pwani Fish'], collect($this->list($url, ['page' => 1, 'per_page' => 5, 'search' => 'pwani'])->json('data.items'))->pluck('startup_name')->all());

        $this->list($url, ['page' => 1, 'per_page' => 5], $this->orgUser)->assertForbidden();
    }
}
