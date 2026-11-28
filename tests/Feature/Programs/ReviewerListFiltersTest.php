<?php

namespace Tests\Feature\Programs;

use App\Models\Auth\User;
use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\Programs\Monitoring\MESiteVisitFile;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Models\Programs\Rounds\RoundReviewer;
use App\Models\ReviewerOrder;

/** The filters the Business Coach's tables (and a service provider's program work) describe in filter_schema, and apply. */
class ReviewerListFiltersTest extends ProgramTestCase
{
    private function list(string $url, array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->reviewerUser, 'sanctum')->getJson($url.($query ? '?'.http_build_query($query) : ''));
    }

    /** filter_schema keyed by filter key */
    private function schema(string $url, array $query = [], ?User $as = null)
    {
        return collect($this->list($url, $query + ['page' => 1, 'per_page' => 5], $as)->assertOk()->json('data.filter_schema'))->keyBy('key');
    }

    /** How many rows match these filters (every match, not a page). */
    private function matching(string $url, array $filters, array $query = [], ?User $as = null): int
    {
        return count($this->list($url, $query + ['export' => 1, 'filters' => $filters], $as)->assertOk()->json('data.items'));
    }

    private function round(Program $program, array $attributes = []): ProgramRound
    {
        static $n = 10;
        $n++;

        return ProgramRound::factory()->create($attributes + ['program_id' => $program->id, 'round_number' => $n, 'round_name' => "Round $n", 'status' => 'published']);
    }

    public function test_review_requests_describe_and_apply_status_program_fee_dates_and_open(): void
    {
        $green = Program::factory()->create(['user_id' => $this->orgUser->id, 'program_title' => 'Green Growth']);
        $blue = Program::factory()->create(['user_id' => $this->orgUser->id, 'program_title' => 'Blue Water']);
        $soon = $this->round($green, ['close_date' => now()->addDays(5)->toDateString()]);
        $later = $this->round($green, ['close_date' => now()->addDays(40)->toDateString()]);
        $closed = $this->round($blue, ['close_date' => now()->subDays(3)->toDateString()]);
        RoundReviewer::create(['round_id' => $soon->id, 'user_id' => $this->reviewerUser->id, 'acceptance_status' => 'pending', 'reviewer_fee' => 50, 'fee_type' => 'per_application']);
        RoundReviewer::create(['round_id' => $later->id, 'user_id' => $this->reviewerUser->id, 'acceptance_status' => 'accepted', 'reviewer_fee' => 300, 'fee_type' => 'flat']);
        $old = RoundReviewer::create(['round_id' => $closed->id, 'user_id' => $this->reviewerUser->id, 'acceptance_status' => 'declined', 'reviewer_fee' => 0]);
        $old->forceFill(['created_at' => now()->subDays(60)])->save();

        $url = '/api/v1/programs/reviewer/assigned-requests';
        $schema = $this->schema($url);
        $this->assertSame(['status', 'program', 'fee', 'amount', 'closes', 'open', 'requested'], $schema->keys()->all());
        $this->assertSame([
            ['value' => 'pending', 'count' => 1, 'label' => 'app:pending2'],
            ['value' => 'accepted', 'count' => 1, 'label' => 'app:accepted'],
            ['value' => 'declined', 'count' => 1, 'label' => 'app:declined'],
        ], $schema['status']['options']);
        $this->assertEquals([$green->id => 2, $blue->id => 1], collect($schema['program']['options'])->pluck('count', 'value')->all());
        $this->assertSame('Green Growth', collect($schema['program']['options'])->firstWhere('value', (string) $green->id)['label']);
        $this->assertEquals(['per_application' => 1, 'flat' => 1, 'none' => 1], collect($schema['fee']['options'])->pluck('count', 'value')->all());
        $this->assertSame([0, 300], [$schema['amount']['min'], $schema['amount']['max']]);
        $this->assertContains('next7Days', $schema['closes']['presets']);
        $this->assertSame([['value' => 'yes', 'count' => 2], ['value' => 'no', 'count' => 1]], $schema['open']['options']);

        $this->assertSame(2, $this->matching($url, ['program' => [(string) $green->id]]));
        $this->assertSame(2, $this->matching($url, ['program' => ['Green Growth']])); // older links sent the title
        $this->assertSame(1, $this->matching($url, ['amount' => '100~']));
        $this->assertSame(2, $this->matching($url, ['amount' => '~100']));
        $this->assertSame(1, $this->matching($url, ['closes' => now()->toDateString().'~'.now()->addDays(7)->toDateString()]));
        $this->assertSame(1, $this->matching($url, ['open' => 'no']));
        $this->assertSame(1, $this->matching($url, ['open' => 'yes', 'fee' => ['flat']]));
        $this->assertSame(2, $this->matching($url, ['requested' => now()->subDays(7)->toDateString().'~']));

        // ?state=pending: every row is pending, so there is no status to choose (the rest stays)
        $this->assertSame(['program', 'fee', 'amount', 'closes', 'open', 'requested'], $this->schema($url, ['state' => 'pending'])->keys()->all());

        // counts follow the other filters
        $narrowed = $this->schema($url, ['filters' => ['open' => 'yes']]);
        $this->assertEquals(['pending' => 1, 'accepted' => 1, 'declined' => 0], collect($narrowed['status']['options'])->pluck('count', 'value')->all());
    }

    public function test_accepted_rounds_describe_and_apply_program_dates_applications_fee_and_booleans(): void
    {
        $program = Program::factory()->create(['user_id' => $this->orgUser->id, 'program_title' => 'Agri Boost']);
        $busy = $this->round($program, ['close_date' => now()->addDays(10)->toDateString(), 'open_date' => now()->subDays(10)->toDateString()]);
        $quiet = $this->round($this->program, ['close_date' => now()->subDays(2)->toDateString(), 'status' => 'closed']);
        RoundReviewer::create(['round_id' => $busy->id, 'user_id' => $this->reviewerUser->id, 'acceptance_status' => 'accepted', 'reviewer_fee' => 500]);
        RoundReviewer::create(['round_id' => $quiet->id, 'user_id' => $this->reviewerUser->id, 'acceptance_status' => 'accepted', 'reviewer_fee' => 20]);
        $apps = ProgramApplication::factory()->count(3)->create(['program_id' => $program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $busy->id, 'round_status' => 'submitted']);
        ReviewerApplicationAssignment::create(['round_id' => $busy->id, 'reviewer_id' => $this->reviewerUser->id, 'application_id' => $apps[0]->id, 'status' => 'assigned']);
        ReviewerOrder::factory()->create(['organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'program_id' => $this->program->id, 'round_id' => $quiet->id, 'order_type' => 'round_review', 'fee_amount' => 20, 'payment_status' => 'completed']);

        $url = '/api/v1/programs/reviewer/assigned-rounds';
        $schema = $this->schema($url);
        $this->assertSame(['status', 'program', 'closes', 'opens', 'applications', 'fee', 'open', 'to_review', 'paid'], $schema->keys()->all());
        $this->assertEquals(['published' => 1, 'closed' => 1], collect($schema['status']['options'])->pluck('count', 'value')->all());
        $this->assertSame('Agri Boost', collect($schema['program']['options'])->firstWhere('value', (string) $program->id)['label']);
        $this->assertSame([0, 3], [$schema['applications']['min'], $schema['applications']['max']]);
        $this->assertSame([20, 500], [$schema['fee']['min'], $schema['fee']['max']]);
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 1]], $schema['to_review']['options']);

        $ids = fn (array $filters) => collect($this->list($url, ['export' => 1, 'filters' => $filters])->json('data.items'))->pluck('id')->all();
        $this->assertSame([$busy->id], $ids(['program' => [(string) $program->id]]));
        $this->assertSame([$busy->id], $ids(['applications' => '1~']));
        $this->assertSame([$quiet->id], $ids(['fee' => '~100']));
        $this->assertSame([$busy->id], $ids(['open' => 'yes']));
        $this->assertSame([$busy->id], $ids(['to_review' => 'yes']));
        $this->assertSame([$quiet->id], $ids(['paid' => 'yes']));
        $this->assertSame([$quiet->id], $ids(['closes' => now()->subDays(5)->toDateString().'~'.now()->toDateString()]));
        $this->assertSame([$busy->id], $ids(['opens' => now()->subDays(30)->toDateString().'~']));
        $this->assertSame([$quiet->id], $ids(['status' => ['closed']]));
    }

    public function test_a_rounds_applications_filter_by_review_status_sector_score_and_dates(): void
    {
        $apps = ProgramApplication::factory()->count(3)->create(['program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'current_round_id' => $this->round->id, 'status' => 'pending', 'sector' => 'Agriculture']);
        $apps[2]->update(['sector' => 'Energy', 'status' => 'approved']);
        foreach ($apps as $i => $app) {
            ReviewerApplicationAssignment::create(['round_id' => $this->round->id, 'reviewer_id' => $this->reviewerUser->id, 'application_id' => $app->id,
                'status' => $i === 0 ? 'assigned' : 'completed', 'assigned_at' => now()->subDays(10 - $i), 'completed_at' => $i === 0 ? null : now()->subDays(1)]);
        }
        ApplicationScore::factory()->create(['application_id' => $apps[1]->id, 'round_id' => $this->round->id, 'reviewer_id' => $this->reviewerUser->id, 'total_score' => 65]);
        ApplicationScore::factory()->create(['application_id' => $apps[2]->id, 'round_id' => $this->round->id, 'reviewer_id' => $this->reviewerUser->id, 'total_score' => 90]);
        ApplicationScore::factory()->create(['application_id' => $apps[0]->id, 'round_id' => $this->round->id, 'reviewer_id' => $this->orgUser->id, 'total_score' => 10]); // someone else's score

        $url = "/api/v1/programs/rounds/{$this->round->id}/my-applications";
        $schema = $this->schema($url);
        $this->assertSame(['review', 'status', 'sector', 'score', 'scored', 'assigned', 'completed', 'submitted'], $schema->keys()->all());
        $this->assertEquals(['assigned' => 1, 'in_review' => 0, 'completed' => 2], collect($schema['review']['options'])->pluck('count', 'value')->all());
        $this->assertEquals(['pending' => 2, 'approved' => 1], collect($schema['status']['options'])->pluck('count', 'value')->all());
        $this->assertEquals(['Agriculture' => 2, 'Energy' => 1], collect($schema['sector']['options'])->pluck('count', 'value')->all());
        $this->assertSame([65, 90], [$schema['score']['min'], $schema['score']['max']]);
        $this->assertSame([['value' => 'yes', 'count' => 2], ['value' => 'no', 'count' => 1]], $schema['scored']['options']);

        $this->assertSame(1, $this->matching($url, ['score' => '80~']));
        $this->assertSame(1, $this->matching($url, ['scored' => 'no']));
        $this->assertSame(1, $this->matching($url, ['sector' => ['Energy']]));
        $this->assertSame(1, $this->matching($url, ['status' => ['approved']]));
        $this->assertSame(2, $this->matching($url, ['completed' => now()->subDays(2)->toDateString().'~']));
        $this->assertSame(1, $this->matching($url, ['assigned' => '~'.now()->subDays(10)->toDateString()]));
    }

    public function test_coach_orders_describe_and_apply_entities_money_dates_and_booleans(): void
    {
        $other = $this->round($this->program, ['round_name' => 'Finals']);
        $order = fn (array $attributes) => ReviewerOrder::factory()->create($attributes + [
            'organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'program_id' => $this->program->id,
            'round_id' => $this->round->id, 'order_type' => 'round_review', 'currency' => 'KES', 'fee_type' => 'flat',
        ]);
        $late = $order(['fee_amount' => 100, 'work_status' => 'in_progress', 'deadline' => now()->subDays(2), 'payment_status' => 'unpaid']);
        $waiting = $order(['fee_amount' => 400, 'work_status' => 'delivered', 'deadline' => now()->addDays(3), 'payment_status' => 'unpaid', 'round_id' => $other->id]);
        $paid = $order(['fee_amount' => 250, 'work_status' => 'approved', 'payment_status' => 'completed', 'paid_at' => now()->subDay(), 'currency' => 'USD', 'fee_type' => 'per_application']);
        $free = $order(['fee_amount' => 0, 'work_status' => 'assigned']);

        $url = '/api/v1/programs/reviewer/orders';
        $schema = $this->schema($url);
        $this->assertSame(['work', 'payment', 'type', 'program', 'round', 'fee_type', 'currency', 'fee', 'overdue', 'awaiting_payment', 'deadline', 'created', 'paid'], $schema->keys()->all());
        $this->assertEquals(['none' => 1, 'unpaid' => 2, 'completed' => 1], collect($schema['payment']['options'])->filter(fn ($o) => $o['count'] > 0)->pluck('count', 'value')->all());
        $this->assertSame('app:paid', collect($schema['payment']['options'])->firstWhere('value', 'completed')['label']);
        $this->assertSame('Finals', collect($schema['round']['options'])->firstWhere('value', (string) $other->id)['label']);
        $this->assertEquals(['KES' => 3, 'USD' => 1], collect($schema['currency']['options'])->pluck('count', 'value')->all());
        $this->assertSame([0, 400], [$schema['fee']['min'], $schema['fee']['max']]);
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 3]], $schema['overdue']['options']);
        $this->assertContains('next30Days', $schema['deadline']['presets']);

        $ids = fn (array $filters) => collect($this->list($url, ['export' => 1, 'filters' => $filters, 'sort' => 'id'])->json('data.items'))->pluck('id')->all();
        $this->assertSame([$late->id], $ids(['overdue' => 'yes']));
        $this->assertSame([$waiting->id], $ids(['awaiting_payment' => 'yes']));
        $this->assertSame([$waiting->id, $paid->id], $ids(['fee' => '200~']));
        $this->assertSame([$waiting->id], $ids(['round' => [(string) $other->id]]));
        $this->assertSame([$paid->id], $ids(['fee_type' => ['per_application']]));
        $this->assertSame([$paid->id], $ids(['currency' => ['USD']]));
        $this->assertSame([$paid->id], $ids(['paid' => now()->subDays(3)->toDateString().'~']));
        $this->assertSame([$waiting->id], $ids(['deadline' => now()->toDateString().'~'.now()->addDays(7)->toDateString()]));
        $this->assertSame([$free->id], $ids(['payment' => ['none']]));
        $this->assertCount(4, $ids(['program' => [(string) $this->program->id], 'created' => now()->subDay()->toDateString().'~']));
    }

    public function test_coach_site_visits_describe_and_apply_stage_entities_fee_dates_and_booleans(): void
    {
        $checkpoint = MECheckpoint::factory()->create(['app_id' => $this->application->id, 'program_id' => $this->program->id, 'checkpoint_name' => 'Farm check', 'sent_at' => now()]);
        $visit = fn (array $attributes = []) => MESiteVisit::factory()->create($attributes + [
            'checkpoint_id' => $checkpoint->id, 'app_id' => $this->application->id, 'reviewer_id' => $this->reviewerUser->id,
            'status' => 'scheduled', 'location' => 'Westlands, Nairobi, Kenya', 'start_date' => now()->addDays(3)->toDateString(), 'assign_type' => 'internal',
        ]);
        $next = $visit();
        $late = $visit(['start_date' => now()->subDays(4)->toDateString(), 'location' => 'Eldoret, Kenya']);
        $audit = $visit(['assign_type' => 'third_party_audit', 'status' => 'completed', 'start_date' => now()->subDays(20)->toDateString()]);
        ReviewerOrder::factory()->create(['organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'program_id' => $this->program->id, 'order_type' => 'site_visit', 'site_visit_id' => $audit->id, 'acceptance_status' => 'accepted', 'fee_amount' => 800, 'payment_status' => 'completed']);
        ReviewerOrder::factory()->create(['organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'program_id' => $this->program->id, 'order_type' => 'site_visit', 'site_visit_id' => $next->id, 'acceptance_status' => 'accepted', 'fee_amount' => 150]);
        MESiteVisitFile::create(['site_visit_id' => $audit->id, 'file_path' => 'visits/photo.jpg', 'original_filename' => 'photo.jpg', 'mime_type' => 'image/jpeg']);

        $url = "/api/v1/programs/monitoring/reviewer/{$this->reviewerUser->id}/site-visits";
        $schema = $this->schema($url);
        $this->assertSame(['status', 'checkpoint', 'program', 'business', 'location', 'type', 'date', 'fee', 'upcoming', 'overdue', 'paid', 'files'], $schema->keys()->all());
        $this->assertEquals(['pending' => 0, 'active' => 2, 'completed' => 1, 'declined' => 0, 'cancelled' => 0], collect($schema['status']['options'])->pluck('count', 'value')->all());
        $this->assertSame($this->program->program_title, collect($schema['program']['options'])->firstWhere('value', (string) $this->program->id)['label']);
        $this->assertSame($this->application->startup_name, $schema['business']['options'][0]['label']);
        $this->assertEquals(['Nairobi, Kenya' => 2, 'Eldoret, Kenya' => 1], collect($schema['location']['options'])->pluck('count', 'value')->all());
        $this->assertEquals(['site_visit' => 2, 'third_party_audit' => 1], collect($schema['type']['options'])->pluck('count', 'value')->all());
        $this->assertSame([150, 800], [$schema['fee']['min'], $schema['fee']['max']]);

        $ids = fn (array $filters) => collect($this->list($url, ['export' => 1, 'filters' => $filters, 'sort' => 'date'])->json('data.items'))->pluck('id')->all();
        $this->assertSame([$next->id], $ids(['upcoming' => 'yes']));
        $this->assertSame([$late->id], $ids(['overdue' => 'yes']));
        $this->assertSame([$audit->id, $late->id], $ids(['upcoming' => 'no']));
        $this->assertSame([$audit->id], $ids(['paid' => 'yes']));
        $this->assertSame([$audit->id], $ids(['files' => 'yes']));
        $this->assertSame([$audit->id], $ids(['type' => ['third_party_audit']]));
        $this->assertSame([$audit->id], $ids(['fee' => '500~']));
        $this->assertSame([$late->id, $next->id], $ids(['date' => now()->subDays(7)->toDateString().'~'.now()->addDays(7)->toDateString()]));
        $this->assertCount(3, $ids(['program' => [(string) $this->program->id], 'business' => [(string) $this->application->id]]));
        $this->assertCount(0, $ids(['program' => ['999999']]));
        // the old facets stay (only options that have rows)
        $this->assertEquals(['active' => 2, 'completed' => 1], collect($this->list($url, ['page' => 1])->json('data.facets.status'))->pluck('count', 'value')->all());
    }

    public function test_program_work_describes_only_what_the_rows_can_answer(): void
    {
        $provider = User::factory()->create(['user_type_id' => 3]);
        $this->createVerifiedKyc($provider, 'service_provider');
        $open = $this->round($this->program, ['close_date' => now()->addDays(4)->toDateString(), 'round_name' => 'Open round']);
        $past = $this->round($this->program, ['close_date' => now()->subDays(4)->toDateString()]);
        RoundReviewer::create(['round_id' => $open->id, 'user_id' => $provider->id, 'acceptance_status' => 'pending', 'reviewer_fee' => 75]);
        RoundReviewer::create(['round_id' => $past->id, 'user_id' => $provider->id, 'acceptance_status' => 'accepted', 'reviewer_fee' => 300]);

        $url = '/api/v1/programs/provider/work';
        $schema = $this->schema($url, ['type' => 'review'], $provider);
        // one kind of work, no businesses, one support type, no payments: those are not offered
        $this->assertSame(['status', 'program', 'amount', 'due', 'upcoming', 'answer'], $schema->keys()->all());
        $this->assertSame([75, 300], [$schema['amount']['min'], $schema['amount']['max']]);
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 1]], $schema['answer']['options']);

        $count = fn (array $filters) => $this->matching($url, $filters, ['type' => 'review'], $provider);
        $this->assertSame(1, $count(['upcoming' => 'yes']));
        $this->assertSame(1, $count(['answer' => 'yes', 'upcoming' => 'yes']));
        $this->assertSame(1, $count(['amount' => '100~']));
        $this->assertSame(1, $count(['due' => '~'.now()->toDateString()]));
        $this->assertSame(2, $count(['program' => [$this->program->program_title]]));

        // with site visits as well, the kind and the business show up
        $checkpoint = MECheckpoint::factory()->create(['app_id' => $this->application->id, 'program_id' => $this->program->id, 'sent_at' => now()]);
        $visit = MESiteVisit::factory()->create(['checkpoint_id' => $checkpoint->id, 'app_id' => $this->application->id, 'reviewer_id' => $provider->id, 'status' => 'scheduled', 'start_date' => now()->addDays(2)->toDateString()]);
        ReviewerOrder::factory()->create(['organization_id' => $this->organization->id, 'reviewer_id' => $provider->id, 'program_id' => $this->program->id, 'order_type' => 'site_visit', 'site_visit_id' => $visit->id, 'acceptance_status' => 'pending', 'fee_amount' => 40, 'payment_status' => 'unpaid']);
        $all = $this->schema($url, ['type' => 'all'], $provider);
        $this->assertSame(['kind', 'status', 'program', 'business', 'support', 'amount', 'paid', 'due', 'upcoming', 'answer'], $all->keys()->all());
        $this->assertEquals(['review_request' => 2, 'inspection' => 1], collect($all['kind']['options'])->pluck('count', 'value')->all());
        $this->assertSame(2, $this->matching($url, ['answer' => 'yes'], ['type' => 'all'], $provider));
        $this->assertSame(1, $this->matching($url, ['kind' => ['inspection'], 'paid' => 'no'], ['type' => 'all'], $provider));
    }
}
