<?php

namespace Tests\Feature\Programs;

use App\Models\Auth\User;
use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\Programs\Monitoring\MESiteVisitFile;
use App\Models\Programs\Program;
use App\Models\ReviewerOrder;

/** The owner's Business Coach orders (Orders page, Site visits page) search, filter, sort, page and export on the server. */
class OwnerOrdersListTest extends ProgramTestCase
{
    private const URL = '/api/v1/programs/owner/reviewer-orders';

    private function orders(array $query = [])
    {
        return $this->actingAsOrgOwner()->getJson(self::URL.'?'.http_build_query($query));
    }

    private function roundOrders(int $count, array $attributes = []): void
    {
        ReviewerOrder::factory()->count($count)->create($attributes + [
            'organization_id' => $this->organization->id,
            'reviewer_id' => $this->reviewerUser->id,
            'program_id' => $this->program->id,
            'round_id' => $this->round->id,
            'order_type' => 'round_review',
            'fee_amount' => 100,
            'currency' => 'KES',
        ]);
    }

    private function siteVisitOrder(array $attributes = [], ?Program $program = null, string $checkpoint = 'Equipment check'): ReviewerOrder
    {
        $program ??= $this->program;
        $check = MECheckpoint::factory()->create(['app_id' => $this->application->id, 'program_id' => $program->id, 'checkpoint_name' => $checkpoint]);
        $visit = MESiteVisit::factory()->create(['checkpoint_id' => $check->id, 'app_id' => $this->application->id, 'reviewer_id' => $this->reviewerUser->id, 'location' => 'Arusha']);

        return ReviewerOrder::factory()->create($attributes + [
            'organization_id' => $this->organization->id,
            'reviewer_id' => $this->reviewerUser->id,
            'program_id' => $program->id,
            'order_type' => 'site_visit',
            'site_visit_id' => $visit->id,
            'fee_amount' => 50,
            'currency' => 'KES',
        ]);
    }

    public function test_orders_page_on_the_server_with_stats_facets_and_a_full_export(): void
    {
        $this->roundOrders(18, ['work_status' => 'in_progress']);
        $this->roundOrders(4, ['work_status' => 'delivered']);
        $this->roundOrders(3, ['work_status' => 'approved', 'payment_status' => 'completed']);
        $this->siteVisitOrder(['work_status' => 'delivered']);

        $page = $this->orders(['program' => $this->program->id, 'page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(26, $page->json('data.pagination.total'));
        $this->assertSame(26, $page->json('data.stats.total'));
        $this->assertSame(18, $page->json('data.stats.tabs.pending'));
        $this->assertSame(5, $page->json('data.stats.tabs.delivered'));
        $this->assertSame(3, $page->json('data.stats.tabs.completed'));
        $this->assertEquals(2550, $page->json('data.stats.fees.total'));
        $this->assertEquals(300, $page->json('data.stats.fees.paid'));
        $this->assertSame('KES', $page->json('data.stats.fees.currency'));
        $this->assertSame($this->program->program_title, $page->json('data.items.0.program_title'));

        $types = collect($page->json('data.facets.type'))->pluck('count', 'value')->all();
        $this->assertEquals(['round_review' => 25, 'site_visit' => 1], $types);
        $payment = collect($page->json('data.facets.payment'))->pluck('count', 'value')->all();
        $this->assertEquals(['unpaid' => 23, 'paid' => 3], $payment);
        $this->assertSame(trim($this->reviewerUser->first_name.' '.$this->reviewerUser->last_name), $page->json('data.facets.coach.0.label'));

        // the export is every match, not a page, and follows the tab and the filters
        $all = $this->orders(['program' => $this->program->id, 'export' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(26, $all->json('data.items'));
        $this->assertNull($all->json('data.pagination'));
        $this->assertCount(5, $this->orders(['program' => $this->program->id, 'export' => 1, 'filters' => ['tab' => ['delivered']]])->json('data.items'));
        $this->assertCount(3, $this->orders(['program' => $this->program->id, 'export' => 1, 'filters' => ['payment' => ['paid']]])->json('data.items'));
        $this->assertSame(4, $this->orders(['program' => $this->program->id, 'page' => 1, 'per_page' => 2, 'filters' => ['tab' => ['delivered'], 'type' => ['round_review']]])->json('data.pagination.total'));
        $tab = $this->orders(['program' => $this->program->id, 'tab' => 'delivered', 'page' => 1, 'per_page' => 10])->json('data');
        $this->assertSame(5, $tab['pagination']['total']);
        $this->assertEquals(['round_review' => 4, 'site_visit' => 1], collect($tab['facets']['type'])->pluck('count', 'value')->all());
        $this->assertSame(26, $tab['stats']['total']);

        // a stable sort, and a page past the first
        $ids = collect($this->orders(['program' => $this->program->id, 'page' => 3, 'per_page' => 10, 'sort' => 'id'])->json('data.items'))->pluck('id');
        $this->assertCount(6, $ids);
        $this->assertSame($ids->sort()->values()->all(), $ids->all());
    }

    public function test_search_finds_site_visits_by_checkpoint_and_coach(): void
    {
        $this->roundOrders(3);
        $this->siteVisitOrder([], null, 'Zawadi warehouse handover');

        $found = $this->orders(['page' => 1, 'per_page' => 10, 'search' => 'zawadi'])->assertOk()->json('data.items');
        $this->assertCount(1, $found);
        $this->assertSame('Zawadi warehouse handover', $found[0]['round_name']);
        $this->assertSame('site_visit', $found[0]['order_type']);
        $this->assertSame('Arusha', $found[0]['location']);

        $byCoach = $this->orders(['export' => 1, 'search' => $this->reviewerUser->email])->json('data.items');
        $this->assertCount(4, $byCoach);

        // the Site visits page: only site visit orders, the stats too
        $visits = $this->orders(['order_type' => 'site_visit', 'page' => 1, 'per_page' => 10])->assertOk();
        $this->assertSame(1, $visits->json('data.pagination.total'));
        $this->assertSame(1, $visits->json('data.stats.total'));
        $this->assertSame(1, $visits->json('data.stats.coaches'));
    }

    public function test_owners_only_see_orders_of_their_own_programs(): void
    {
        $this->roundOrders(2);
        $someone = User::factory()->create(['user_type_id' => 4]);
        $theirs = Program::factory()->create(['user_id' => $someone->id, 'status' => 'published', 'visible' => 1]);
        ReviewerOrder::factory()->create([
            'organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'program_id' => $theirs->id,
            'order_type' => 'round_review', 'fee_amount' => 10,
        ]);

        $this->assertCount(2, $this->orders(['export' => 1])->json('data.items'));
        $this->orders(['program' => $theirs->id])->assertForbidden();
        $this->actingAs($someone, 'sanctum')->getJson(self::URL.'?program='.$this->program->id)->assertForbidden();
        $this->assertCount(1, $this->actingAs($someone, 'sanctum')->getJson(self::URL.'?export=1')->json('data.items'));

        // the per-program endpoints are unchanged
        $this->actingAsOrgOwner()->getJson('/api/v1/programs/rounds/'.$this->round->id.'/reviewer-orders')->assertOk()->assertJsonCount(2, 'data');
    }

    /** The Site visit detail page opens one order by id (filters[id][]), with the visit's own fields and files. */
    public function test_one_site_visit_order_by_id_with_its_files(): void
    {
        $this->roundOrders(2);
        $order = $this->siteVisitOrder(['work_status' => 'delivered'], null, 'Warehouse check');
        $order->siteVisit->update(['objective' => 'Confirm the stock', 'observed_actions' => 'Stock counted']);
        MESiteVisitFile::create(['site_visit_id' => $order->site_visit_id, 'file_path' => 'visits/photo.jpg', 'original_filename' => 'photo.jpg', 'mime_type' => 'image/jpeg']);

        $query = ['order_type' => 'site_visit', 'filters' => ['id' => [$order->id]], 'page' => 1, 'per_page' => 1, 'with_visit' => 1];
        $row = $this->orders($query)->assertOk()->json('data.items.0');
        $this->assertSame($order->id, $row['id']);
        $this->assertSame('Warehouse check', $row['round_name']);
        $this->assertSame('Confirm the stock', $row['site_visit']['objective']);
        $this->assertSame('Stock counted', $row['site_visit']['observed_actions']);
        $this->assertSame(['photo.jpg'], collect($row['visit_files'])->pluck('original_filename')->all());
        $this->assertNotSame('visits/photo.jpg', $row['visit_files'][0]['file_path']); // a link, not the storage key

        // without with_visit the rows stay as they were; another owner gets nothing for this id
        $this->assertArrayNotHasKey('visit_files', $this->orders(['filters' => ['id' => [$order->id]], 'page' => 1, 'per_page' => 1])->json('data.items.0'));
        $someone = User::factory()->create(['user_type_id' => 4]);
        $this->assertSame([], $this->actingAs($someone, 'sanctum')->getJson(self::URL.'?'.http_build_query($query))->assertOk()->json('data.items'));
    }

    /** "Program · round" and "Business Coach" sort on the server (subqueries on the program, round and coach). */
    public function test_orders_sort_by_program_and_round_and_by_business_coach(): void
    {
        $second = \App\Models\Programs\Rounds\ProgramRound::factory()->create(['program_id' => $this->program->id, 'round_number' => 2, 'status' => 'published']);
        $other = Program::factory()->create(['user_id' => $this->orgUser->id, 'status' => 'published', 'program_title' => 'AAA First program']);
        $otherRound = \App\Models\Programs\Rounds\ProgramRound::factory()->create(['program_id' => $other->id, 'round_number' => 1, 'status' => 'published']);
        $this->program->update(['program_title' => 'ZZZ Last program']);
        $amina = User::factory()->create(['user_type_id' => 6, 'first_name' => 'Amina', 'last_name' => 'Bakari']);
        $zuberi = User::factory()->create(['user_type_id' => 6, 'first_name' => 'Zuberi', 'last_name' => 'Mollel']);

        $make = fn (Program $p, $round, User $coach) => ReviewerOrder::factory()->create([
            'organization_id' => $this->organization->id, 'reviewer_id' => $coach->id, 'program_id' => $p->id,
            'round_id' => $round->id, 'order_type' => 'round_review', 'fee_amount' => 10,
        ])->id;
        $z2 = $make($this->program, $second, $amina);
        $a1 = $make($other, $otherRound, $zuberi);
        $z1 = $make($this->program, $this->round, $zuberi);

        $ids = fn (string $sort) => collect($this->orders(['export' => 1, 'sort' => $sort])->assertOk()->json('data.items'))->pluck('id')->all();
        $this->assertSame([$a1, $z1, $z2], $ids('program'));
        $this->assertSame([$z2, $z1, $a1], $ids('-program'));
        $this->assertSame($z2, $ids('coach')[0]);
        $this->assertSame($z2, $ids('-coach')[2]);
        // paged the same way
        $this->assertSame([$a1], collect($this->orders(['page' => 1, 'per_page' => 1, 'sort' => 'program'])->json('data.items'))->pluck('id')->all());
    }

    public function test_orders_describe_their_filters_and_apply_fee_range_overdue_and_deadline(): void
    {
        $this->roundOrders(2, ['work_status' => 'in_progress', 'fee_amount' => 100, 'deadline' => now()->subDays(2)]);    // overdue
        $this->roundOrders(1, ['work_status' => 'delivered', 'fee_amount' => 400, 'deadline' => now()->subDays(2)]);      // delivered: not overdue
        $this->roundOrders(1, ['work_status' => 'assigned', 'fee_amount' => 900, 'deadline' => now()->addDays(5)]);

        $data = $this->orders(['program' => $this->program->id, 'page' => 1, 'per_page' => 10])->assertOk()->json('data');
        $schema = collect($data['filter_schema'])->keyBy('key');
        $this->assertSame(['work', 'payment', 'type', 'round', 'coach', 'fee', 'overdue', 'deadline', 'created'], $schema->keys()->all());
        $this->assertSame(['options', 'options', 'options', 'options', 'options', 'numberRange', 'boolean', 'dateRange', 'dateRange'], $schema->pluck('type')->all());
        $this->assertSame('app:reviewer', $schema['coach']['label']);
        $this->assertSame('Business Coach', $schema['coach']['fallback']);
        $this->assertTrue($schema['coach']['searchable']);
        $this->assertSame(trim($this->reviewerUser->first_name.' '.$this->reviewerUser->last_name), $schema['coach']['options'][0]['label']);
        // every work status is listed in order (0 when none), with the label key the page translates
        $work = collect($schema['work']['options'])->keyBy('value');
        $this->assertSame(['assigned', 'pending', 'accepted', 'in_progress', 'delivered', 'modification_requested', 'approved', 'rejected'], $work->keys()->all());
        $this->assertSame(['value' => 'in_progress', 'count' => 2, 'label' => 'app:inProgress2'], $work['in_progress']);
        $this->assertSame(0, $work['approved']['count']);
        $this->assertSame(['min' => 100, 'max' => 900], array_intersect_key($schema['fee'], ['min' => 1, 'max' => 1]));
        $this->assertSame([['value' => 'yes', 'count' => 2], ['value' => 'no', 'count' => 2]], $schema['overdue']['options']);
        $this->assertContains('next7Days', $schema['deadline']['presets']);
        // the old facets are still sent next to it
        $this->assertEquals(['in_progress' => 2, 'delivered' => 1, 'assigned' => 1], collect($data['facets']['work'])->pluck('count', 'value')->all());

        $count = fn (array $filters) => $this->orders(['program' => $this->program->id, 'export' => 1, 'filters' => $filters])->json('data.items');
        $this->assertCount(3, $count(['fee' => '100~400']));
        $this->assertCount(2, $count(['fee' => '400~']));
        $this->assertCount(2, $count(['overdue' => 'yes']));
        $this->assertCount(2, $count(['overdue' => 'no']));
        $this->assertCount(0, $count(['overdue' => 'yes', 'fee' => '~50']));
        $this->assertCount(1, $count(['deadline' => now()->addDay()->toDateString().'~'.now()->addDays(7)->toDateString()]));
        // the bounds follow the other filters
        $fee = collect($this->orders(['program' => $this->program->id, 'page' => 1, 'per_page' => 5, 'filters' => ['overdue' => 'no']])->json('data.filter_schema'))->firstWhere('key', 'fee');
        $this->assertSame([400, 900], [$fee['min'], $fee['max']]);
    }

    public function test_site_visits_describe_their_own_filters_and_apply_them(): void
    {
        $other = Program::factory()->create(['user_id' => $this->orgUser->id, 'status' => 'published', 'program_title' => 'Second Fund']);
        $first = $this->siteVisitOrder(['work_status' => 'delivered']);
        $second = $this->siteVisitOrder(['work_status' => 'in_progress'], $other);
        $third = $this->siteVisitOrder(['work_status' => 'in_progress']);
        MESiteVisit::whereKey($first->site_visit_id)->update(['location' => 'Moshi', 'confirm_location' => true, 'start_date' => now()->addDays(3)->toDateString()]);
        MESiteVisit::whereKey($second->site_visit_id)->update(['start_date' => now()->subDays(10)->toDateString()]);
        MESiteVisitFile::create(['site_visit_id' => $first->site_visit_id, 'file_path' => 'visits/a.jpg', 'original_filename' => 'a.jpg', 'mime_type' => 'image/jpeg']);
        $this->roundOrders(2); // round reviews never show on this page

        $schema = collect($this->orders(['order_type' => 'site_visit', 'page' => 1, 'per_page' => 10])->assertOk()->json('data.filter_schema'))->keyBy('key');
        $this->assertSame(['work', 'payment', 'program', 'business', 'location', 'visit', 'coach', 'fee', 'overdue', 'deadline', 'created', 'located', 'files'], $schema->keys()->all());
        $this->assertEquals([$this->program->id => 2, $other->id => 1], collect($schema['program']['options'])->pluck('count', 'value')->all());
        $this->assertSame('Second Fund', collect($schema['program']['options'])->firstWhere('value', (string) $other->id)['label']);
        $this->assertSame($this->application->startup_name, $schema['business']['options'][0]['label']);
        $this->assertEquals(['Arusha' => 2, 'Moshi' => 1], collect($schema['location']['options'])->pluck('count', 'value')->all());
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 2]], $schema['files']['options']);
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 2]], $schema['located']['options']);
        $this->assertContains('next7Days', $schema['visit']['presets']);

        $ids = fn (array $filters) => collect($this->orders(['order_type' => 'site_visit', 'export' => 1, 'filters' => $filters])->json('data.items'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$second->id], $ids(['program' => [$other->id]]));
        $this->assertSame([$first->id], $ids(['location' => ['Moshi']]));
        $this->assertCount(3, $ids(['business' => [$this->application->id]]));
        $this->assertSame([$first->id], $ids(['files' => 'yes']));
        $this->assertSame([$second->id, $third->id], $ids(['located' => 'no']));
        $this->assertSame([$first->id], $ids(['visit' => now()->toDateString().'~'.now()->addDays(7)->toDateString()]));
        $this->assertSame([$second->id], $ids(['visit' => '~'.now()->subDay()->toDateString()]));
        // counts follow the other filters
        $location = collect($this->orders(['order_type' => 'site_visit', 'page' => 1, 'per_page' => 10, 'filters' => ['program' => [$other->id]]])->json('data.filter_schema'))->firstWhere('key', 'location');
        $this->assertEquals(['Arusha' => 1], collect($location['options'])->pluck('count', 'value')->all());
    }
}
