<?php

namespace Tests\Feature\Programs;

use App\Models\Auth\User;
use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Models\Programs\Rounds\RoundReviewer;
use App\Models\ReviewerOrder;

/** The Business Coach's tables (and the service provider's program work) page, search, filter and export on the server. */
class ReviewerListsTest extends ProgramTestCase
{
    private function list(string $url, array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->reviewerUser, 'sanctum')->getJson($url.($query ? '?'.http_build_query($query) : ''));
    }

    /** $count rounds of a new program, each asking $user to review with this status and fee. */
    private function requests(int $count, string $status, array $attributes = [], ?User $user = null, string $title = 'Green Growth'): void
    {
        $program = Program::factory()->create(['user_id' => $this->orgUser->id, 'program_title' => $title, 'status' => 'published', 'visible' => 1]);
        for ($i = 1; $i <= $count; $i++) {
            $round = ProgramRound::factory()->create(['program_id' => $program->id, 'round_number' => $i, 'round_name' => "$title round $i", 'status' => 'published']);
            RoundReviewer::create($attributes + ['round_id' => $round->id, 'user_id' => ($user ?? $this->reviewerUser)->id, 'acceptance_status' => $status]);
        }
    }

    public function test_review_requests_page_filter_search_and_export_on_the_server(): void
    {
        $this->requests(18, 'pending', ['reviewer_fee' => 50, 'fee_type' => 'per_application']);
        $this->requests(6, 'accepted', ['reviewer_fee' => 0], title: 'Blue Water');
        $this->requests(2, 'pending', [], $this->applicantUser, 'Not Mine'); // someone else's requests never show

        $url = '/api/v1/programs/reviewer/assigned-requests';
        $page = $this->list($url, ['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(24, $page->json('data.pagination.total'));
        $this->assertSame(['total' => 24, 'pending' => 18, 'accepted' => 6, 'declined' => 0], $page->json('data.stats'));
        $this->assertSame('pending', $page->json('data.items.0.acceptance_status')); // what needs an answer comes first
        $this->assertEquals(['pending' => 18, 'accepted' => 6], collect($page->json('data.facets.status'))->pluck('count', 'value')->all());
        $this->assertEquals(['Green Growth' => 18, 'Blue Water' => 6], collect($page->json('data.facets.program'))->pluck('count', 'value')->all());
        $this->assertEquals(['per_application' => 18, 'none' => 6], collect($page->json('data.facets.fee'))->pluck('count', 'value')->all());

        $export = $this->list($url, ['export' => 1, 'filters' => ['status' => ['pending']]])->assertOk();
        $this->assertCount(18, $export->json('data.items'));
        $this->assertFalse($export->json('data.export_truncated'));
        $this->assertSame('Green Growth', $export->json('data.items.0.program_round.program.program_title'));

        $this->assertSame(6, $this->list($url, ['page' => 1, 'per_page' => 5, 'filters' => ['fee' => ['none']]])->json('data.pagination.total'));
        $this->assertSame(6, $this->list($url, ['page' => 1, 'per_page' => 5, 'search' => 'blue'])->json('data.pagination.total'));
        $this->assertSame(1, $this->list($url, ['page' => 1, 'per_page' => 5, 'search' => 'growth round 12'])->json('data.pagination.total'));
        $this->assertCount(6, $this->list($url, ['export' => 1, 'filters' => ['program' => ['Blue Water']]])->json('data.items'));

        // ?state=pending keeps only the requests waiting for an answer, while the filters stay free
        $waiting = $this->list($url, ['page' => 1, 'per_page' => 5, 'state' => 'pending', 'filters' => ['program' => ['Blue Water']]]);
        $this->assertSame(0, $waiting->json('data.pagination.total'));
        $this->assertSame(18, $this->list($url, ['page' => 1, 'per_page' => 5, 'state' => 'pending'])->json('data.pagination.total'));

        // without list parameters: the old full list (the Pending requests drawer reads it)
        $this->assertCount(24, $this->list($url)->json('assignment_requests'));
    }

    public function test_accepted_rounds_page_with_application_counts_and_the_coach_fee(): void
    {
        $this->requests(13, 'accepted');
        $this->requests(2, 'pending', title: 'Waiting');
        $round = ProgramRound::query()->where('round_name', 'Green Growth round 1')->first();
        ProgramApplication::factory()->count(3)->create(['program_id' => $round->program_id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $round->id, 'round_status' => 'submitted']);
        ReviewerOrder::factory()->create(['organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'program_id' => $round->program_id, 'round_id' => $round->id, 'order_type' => 'round_review', 'fee_amount' => 150, 'currency' => 'KES']);

        $url = '/api/v1/programs/reviewer/assigned-rounds';
        $page = $this->list($url, ['page' => 1, 'per_page' => 10, 'sort' => 'id'])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(13, $page->json('data.pagination.total'));
        $this->assertSame(13, $page->json('data.stats.total'));
        $this->assertSame($round->id, $page->json('data.items.0.id'));
        $this->assertSame(3, $page->json('data.items.0.applications_count'));
        $this->assertEquals(150, $page->json('data.items.0.order.fee_amount'));

        $this->assertCount(13, $this->list($url, ['export' => 1, 'per_page' => 5])->json('data.items'));
        $this->assertSame(1, $this->list($url, ['page' => 1, 'per_page' => 5, 'search' => 'round 13'])->json('data.pagination.total'));
        $this->assertSame(0, $this->list($url, ['page' => 1, 'per_page' => 5, 'filters' => ['program' => ['Waiting']]])->json('data.pagination.total'));

        $this->assertCount(13, $this->list($url)->json('rounds'));
    }

    public function test_orders_page_filter_and_export_with_totals_per_currency(): void
    {
        $order = fn (array $attributes) => ReviewerOrder::factory()->create($attributes + [
            'organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'program_id' => $this->program->id,
            'round_id' => $this->round->id, 'order_type' => 'round_review', 'currency' => 'KES',
        ]);
        for ($i = 0; $i < 20; $i++) {
            $order(['fee_amount' => 100, 'work_status' => 'approved', 'payment_status' => 'completed']);
        }
        for ($i = 0; $i < 5; $i++) {
            $order(['fee_amount' => 0, 'fee_type' => 'flat', 'work_status' => 'assigned']);
        }
        $order(['fee_amount' => 40, 'currency' => 'USD', 'work_status' => 'delivered', 'payment_status' => 'unpaid']);
        ReviewerOrder::factory()->create(['organization_id' => $this->organization->id, 'reviewer_id' => $this->applicantUser->id, 'program_id' => $this->program->id, 'order_type' => 'round_review']);

        $url = '/api/v1/programs/reviewer/orders';
        $page = $this->list($url, ['page' => 2, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(26, $page->json('data.pagination.total'));
        $this->assertSame(26, $page->json('data.stats.total'));
        $this->assertSame(21, $page->json('data.stats.completed'));
        $this->assertEquals(2000, $page->json('data.stats.by_currency.KES.paid'));
        $this->assertEquals(40, $page->json('data.stats.by_currency.USD.pending_payment'));
        $this->assertEquals(['completed' => 20, 'none' => 5, 'unpaid' => 1], collect($page->json('data.facets.payment'))->pluck('count', 'value')->all());
        $this->assertSame('approved', $page->json('data.items.0.work_status.value')); // the old row shape

        $this->assertCount(5, $this->list($url, ['export' => 1, 'filters' => ['payment' => ['none']]])->json('data.items'));
        $this->assertCount(1, $this->list($url, ['export' => 1, 'filters' => ['work' => ['delivered']]])->json('data.items'));
        $this->assertCount(26, $this->list($url, ['export' => 1, 'per_page' => 10])->json('data.items'));
        $this->assertCount(26, $this->list($url)->json('data')); // the old full list
    }

    public function test_site_visits_page_by_stage_and_only_for_the_coach_themself(): void
    {
        $checkpoint = MECheckpoint::factory()->create(['app_id' => $this->application->id, 'program_id' => $this->program->id, 'checkpoint_name' => 'Farm check', 'sent_at' => now()]);
        $unsent = MECheckpoint::factory()->create(['app_id' => $this->application->id, 'program_id' => $this->program->id, 'sent_at' => null]);
        $visit = fn (array $attributes = []) => MESiteVisit::factory()->create($attributes + [
            'checkpoint_id' => $checkpoint->id, 'app_id' => $this->application->id, 'reviewer_id' => $this->reviewerUser->id,
            'status' => 'scheduled', 'location' => 'Westlands, Nairobi, Kenya', 'start_date' => now()->addDays(3)->toDateString(),
        ]);
        for ($i = 0; $i < 12; $i++) {
            $pending = $visit();
            ReviewerOrder::factory()->create(['organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'program_id' => $this->program->id, 'order_type' => 'site_visit', 'site_visit_id' => $pending->id, 'acceptance_status' => 'pending', 'fee_amount' => 10, 'currency' => 'KES']);
        }
        for ($i = 0; $i < 8; $i++) {
            $visit(['location' => 'Eldoret, Kenya']); // active (no order to answer)
        }
        $visit(['status' => 'completed', 'start_date' => now()->subDays(5)->toDateString()]);
        $visit(['checkpoint_id' => $unsent->id]); // not sent yet: never listed

        $url = "/api/v1/programs/monitoring/reviewer/{$this->reviewerUser->id}/site-visits";
        $page = $this->list($url, ['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(21, $page->json('data.pagination.total'));
        $this->assertEquals(['pending' => 12, 'active' => 8, 'completed' => 1], collect($page->json('data.facets.status'))->pluck('count', 'value')->all());
        $this->assertEquals(['Nairobi, Kenya' => 13, 'Eldoret, Kenya' => 8], collect($page->json('data.facets.location'))->pluck('count', 'value')->all());
        $this->assertSame(['pending' => 12, 'active' => 8, 'completed' => 1, 'declined' => 0, 'cancelled' => 0], $page->json('data.stats.stages'));
        $this->assertSame(8, $page->json('data.stats.upcoming'));
        $this->assertEquals(120, $page->json('data.stats.fees.KES.total'));
        $this->assertSame('pending', $page->json('data.items.0.order.acceptance_status')); // needs an answer first
        $this->assertSame($this->program->program_title, $page->json('data.items.0.program'));
        $this->assertSame($this->application->startup_name, $page->json('data.items.0.application.startup_name'));

        $this->assertCount(8, $this->list($url, ['export' => 1, 'filters' => ['status' => ['active']]])->json('data.items'));
        $this->assertCount(8, $this->list($url, ['export' => 1, 'filters' => ['location' => ['Eldoret, Kenya']]])->json('data.items'));
        $this->assertCount(21, $this->list($url, ['export' => 1, 'per_page' => 10])->json('data.items'));
        $this->assertCount(21, $this->list($url, ['export' => 1, 'search' => 'farm'])->json('data.items'));
        $this->assertCount(21, $this->list($url)->json('data')); // the old full list

        // another person's visits stay closed, as before
        $this->list("/api/v1/programs/monitoring/reviewer/{$this->reviewerUser->id}/site-visits", ['page' => 1], $this->applicantUser)->assertForbidden();
    }

    public function test_a_rounds_assigned_applications_page_and_count_by_review_status(): void
    {
        $apps = ProgramApplication::factory()->count(22)->create(['program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $this->round->id, 'status' => 'pending']);
        $apps->each(fn ($app, $i) => ReviewerApplicationAssignment::create([
            'round_id' => $this->round->id, 'reviewer_id' => $this->reviewerUser->id, 'application_id' => $app->id,
            'status' => $i < 15 ? 'assigned' : 'completed',
        ]));
        $apps[0]->update(['startup_name' => 'Zawadi Farms']);

        $url = "/api/v1/programs/rounds/{$this->round->id}/my-applications";
        $page = $this->list($url, ['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(22, $page->json('data.pagination.total'));
        $this->assertSame(['total' => 22, 'assigned' => 15, 'in_review' => 0, 'completed' => 7], array_intersect_key($page->json('data.stats'), array_flip(['total', 'assigned', 'in_review', 'completed'])));
        $this->assertEquals(['assigned' => 15, 'completed' => 7], collect($page->json('data.facets.review'))->pluck('count', 'value')->all());

        $this->assertCount(7, $this->list($url, ['export' => 1, 'filters' => ['review' => ['completed']]])->json('data.items'));
        $this->assertCount(22, $this->list($url, ['export' => 1, 'per_page' => 10])->json('data.items'));
        $found = $this->list($url, ['page' => 1, 'per_page' => 10, 'search' => 'zawadi'])->json('data.items');
        $this->assertSame(['Zawadi Farms'], collect($found)->pluck('application.startup_name')->all());
        $this->assertSame(0, $page->json('data.stats.scored'));
        $this->assertSame([], $page->json('data.stats.fees'));
        $this->assertCount(22, $this->list($url)->json('data')); // the old full list

        // someone with no assignment in the round sees none of them
        $this->assertSame(0, $this->list($url, ['page' => 1, 'per_page' => 10], $this->orgUser)->json('data.pagination.total'));

        // the dashboard card's split
        $counts = $this->list('/api/v1/programs/reviewer/assignment-counts')->assertOk();
        $this->assertSame(15, $counts->json('not_started'));
        $this->assertSame(7, $counts->json('completed'));
    }

    public function test_program_work_pages_on_the_server_and_stays_for_service_providers(): void
    {
        $provider = User::factory()->create(['user_type_id' => 3]);
        $this->createVerifiedKyc($provider, 'service_provider');
        $this->requests(14, 'pending', ['reviewer_fee' => 20], $provider);
        $this->requests(3, 'accepted', [], $provider, 'Blue Water');

        $url = '/api/v1/programs/provider/work';
        $page = $this->list($url, ['type' => 'review', 'page' => 1, 'per_page' => 10], $provider)->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(17, $page->json('data.pagination.total'));
        $this->assertEquals(['pending' => 14, 'accepted' => 3], collect($page->json('data.facets.status'))->pluck('count', 'value')->all());
        $this->assertArrayNotHasKey('sort', $page->json('data.items.0'));

        $this->assertCount(17, $this->list($url, ['type' => 'review', 'export' => 1, 'per_page' => 10], $provider)->json('data.items'));
        $this->assertCount(3, $this->list($url, ['type' => 'review', 'export' => 1, 'search' => 'blue'], $provider)->json('data.items'));
        $this->assertCount(3, $this->list($url, ['type' => 'review', 'export' => 1, 'filters' => ['status' => ['accepted']]], $provider)->json('data.items'));
        $this->assertCount(17, $this->list($url, ['type' => 'review'], $provider)->json('data')); // the old full list

        $this->list($url, ['type' => 'review', 'page' => 1])->assertForbidden(); // a Business Coach account is not a service provider
    }
}
