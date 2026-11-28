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
use App\Models\Programs\ProgramMilestone;
use Tests\Feature\Programs\ProgramTestCase;

/**
 * Every entrepreneur list describes its filters in `data.filter_schema` (options with counts, entities by name, number
 * ranges with bounds, yes/no toggles and date ranges with presets), and each described filter narrows the rows.
 */
class EntrepreneurListFiltersTest extends ProgramTestCase
{
    private function fetchList(string $url, array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->applicantUser, 'sanctum')->getJson($url.($query ? '?'.http_build_query($query) : ''))->assertOk();
    }

    /** The rows (every match) for some filters, by one field. */
    private function pick(string $url, array $filters, string $field = 'name', array $extra = []): array
    {
        $items = collect($this->fetchList($url, $extra + ['export' => 1, 'filters' => $filters])->json('data.items'))->pluck($field)->all();
        sort($items);

        return $items;
    }

    /** filter_schema keyed by filter key. */
    private function schema(string $url, array $extra = []): array
    {
        return collect($this->fetchList($url, $extra + ['page' => 1, 'per_page' => 5])->json('data.filter_schema'))->keyBy('key')->all();
    }

    private static function counts(array $entry): array
    {
        return collect($entry['options'])->pluck('count', 'value')->all();
    }

    private function listing(array $attributes = []): Listing
    {
        return Listing::create($attributes + [
            'user_id' => $this->applicantUser->id, 'name' => 'Biz '.uniqid(), 'category' => 'Agriculture', 'stage' => 'seed',
            'location' => 'Westlands, Nairobi, Kenya', 'investment_needed' => 1000, 'amount_collected' => 0, 'active' => 1, 'threshold_met' => 0,
        ]);
    }

    private function backdate($model, string $when): void
    {
        $model->newQuery()->whereKey($model->getKey())->update(['created_at' => $when]);
    }

    public function test_my_businesses_describe_and_apply_every_filter(): void
    {
        $met = $this->listing(['name' => 'Met', 'amount_collected' => 1000, 'threshold_met' => 1, 'invest_count' => 3, 'category' => 'Energy']);
        $this->listing(['name' => 'Hidden', 'investment_needed' => 2000, 'amount_collected' => 500, 'active' => 0, 'invest_count' => 0, 'stage' => 'growth']);
        $this->listing(['name' => 'Fresh', 'investment_needed' => null, 'invest_count' => 1, 'location' => 'Kisumu, Kenya']);
        Milestones::create(['user_id' => $this->applicantUser->id, 'listing_id' => $met->id, 'title' => 'Build', 'amount' => 100, 'status' => 'to_do']);
        $this->backdate($met, '2026-01-10 09:00:00');

        $url = '/api/v1/business/listings';
        $schema = $this->schema($url);
        $this->assertSame(['status', 'deal', 'industry', 'stage', 'location', 'goal', 'raised', 'funded', 'investors', 'targetMet', 'hasInvestors', 'hasMilestones', 'created'], array_keys($schema));
        $this->assertSame(['active' => 2, 'inactive' => 1], self::counts($schema['status']));
        $this->assertSame('app:active', $schema['status']['options'][0]['label']);
        $this->assertSame(['open' => 1, 'targetMet' => 1, 'hidden' => 1], self::counts($schema['deal']));
        $this->assertEquals(['Nairobi, Kenya' => 2, 'Kisumu, Kenya' => 1], self::counts($schema['location']));
        $this->assertSame([1000, 2000], [$schema['goal']['min'], $schema['goal']['max']]);
        $this->assertSame('numberRange', $schema['funded']['type']);
        $this->assertSame(['yes' => 1, 'no' => 2], self::counts($schema['targetMet']));
        $this->assertSame('dateRange', $schema['created']['type']);
        $this->assertContains('last30Days', $schema['created']['presets']);

        $this->assertSame(['Hidden'], $this->pick($url, ['status' => ['inactive']]));
        $this->assertSame(['Met'], $this->pick($url, ['deal' => ['targetMet']]));
        $this->assertSame(['Met'], $this->pick($url, ['industry' => ['Energy']]));
        $this->assertSame(['Hidden'], $this->pick($url, ['stage' => ['growth']]));
        $this->assertSame(['Fresh'], $this->pick($url, ['location' => ['Kisumu, Kenya']]));
        $this->assertSame(['Hidden'], $this->pick($url, ['goal' => '1500~']));
        $this->assertSame(['Fresh', 'Hidden'], $this->pick($url, ['raised' => '~600']));
        $this->assertSame(['Met'], $this->pick($url, ['funded' => '50~']));
        $this->assertSame(['Fresh', 'Hidden'], $this->pick($url, ['funded' => '~30']));
        $this->assertSame(['Fresh', 'Met'], $this->pick($url, ['investors' => '1~']));
        $this->assertSame(['Met'], $this->pick($url, ['targetMet' => 'yes']));
        $this->assertSame(['Fresh', 'Hidden'], $this->pick($url, ['targetMet' => 'no']));
        $this->assertSame(['Fresh', 'Met'], $this->pick($url, ['hasInvestors' => 'yes']));
        $this->assertSame(['Met'], $this->pick($url, ['hasMilestones' => 'yes']));
        $this->assertSame(['Fresh', 'Hidden'], $this->pick($url, ['hasMilestones' => 'no']));
        $this->assertSame(['Met'], $this->pick($url, ['created' => '2026-01-01~2026-01-31']));
        // counts follow the other filters
        $this->assertSame(['active' => 1, 'inactive' => 0], self::counts(collect($this->fetchList($url, ['page' => 1, 'per_page' => 5, 'filters' => ['hasMilestones' => 'yes']])->json('data.filter_schema'))->firstWhere('key', 'status')));
        // an export carries no schema
        $this->assertSame([], $this->fetchList($url, ['export' => 1])->json('data.filter_schema'));
    }

    public function test_milestones_table_describes_and_applies_every_filter(): void
    {
        $full = $this->listing(['name' => 'Full', 'stage' => 'growth']);
        $partial = $this->listing(['name' => 'Partial']);
        $this->listing(['name' => 'Empty', 'active' => 0]);
        $milestone = fn (Listing $l, array $a) => Milestones::create($a + ['user_id' => $this->applicantUser->id, 'listing_id' => $l->id, 'title' => 'M']);
        $milestone($full, ['title' => 'Build', 'status' => 'done', 'amount' => 600, 'start_date' => '2026-01-01']);
        $milestone($full, ['title' => 'Launch', 'status' => 'in_progress', 'amount' => 500, 'start_date' => '2026-02-01', 'deadline_date' => '2020-01-01']);
        $milestone($partial, ['title' => 'Prototype', 'status' => 'to_do', 'amount' => 200, 'deadline_date' => '2099-01-15']);
        $this->backdate($partial, '2026-03-03 10:00:00');

        $url = '/api/v1/business/add_milestones';
        $schema = $this->schema($url);
        $this->assertSame(['milestone', 'plan', 'business', 'industry', 'stage', 'progress', 'coverage', 'goal', 'planned', 'count', 'hasMilestones', 'planComplete', 'overdue', 'due', 'created'], array_keys($schema));
        $this->assertSame(['full' => 1, 'partial' => 1, 'none' => 1], self::counts($schema['plan']));
        $this->assertSame([0, 110], [$schema['coverage']['min'], $schema['coverage']['max']]);
        $this->assertSame(['yes' => 1, 'no' => 2], self::counts($schema['overdue']));
        $this->assertContains('next30Days', $schema['due']['presets']);

        $this->assertSame(['Full'], $this->pick($url, ['milestone' => ['in_progress']]));
        $this->assertSame(['Empty'], $this->pick($url, ['business' => ['inactive']]));
        $this->assertSame(['Full'], $this->pick($url, ['stage' => ['growth']]));
        $this->assertSame(['Full'], $this->pick($url, ['progress' => '40~']));
        $this->assertSame(['Empty', 'Partial'], $this->pick($url, ['coverage' => '~50']));
        $this->assertSame(['Empty', 'Full', 'Partial'], $this->pick($url, ['goal' => '1000~1000']));
        $this->assertSame(['Full'], $this->pick($url, ['planned' => '1000~']));
        $this->assertSame(['Full', 'Partial'], $this->pick($url, ['count' => '1~']));
        $this->assertSame(['Empty'], $this->pick($url, ['hasMilestones' => 'no']));
        $this->assertSame(['Full'], $this->pick($url, ['planComplete' => 'yes']));
        $this->assertSame(['Full'], $this->pick($url, ['overdue' => 'yes']));
        $this->assertSame(['Partial'], $this->pick($url, ['due' => '2099-01-01~2099-01-31']));
        $this->assertSame(['Partial'], $this->pick($url, ['created' => '2026-03-01~2026-03-31']));
    }

    public function test_investment_bids_describe_and_apply_filters_on_every_tab(): void
    {
        $farm = $this->listing(['name' => 'Farm', 'threshold_met' => 1]);
        $shop = $this->listing(['name' => 'Shop']);
        Milestones::create(['user_id' => $this->applicantUser->id, 'listing_id' => $farm->id, 'title' => 'Irrigation', 'amount' => 500, 'status' => 'to_do', 'active' => 1]);
        $amina = User::factory()->create(['user_type_id' => 2, 'first_name' => 'Amina', 'last_name' => 'Otieno']);
        $brian = User::factory()->create(['user_type_id' => 2, 'first_name' => 'Brian', 'last_name' => 'Kamau']);
        $bid = fn (string $model, Listing $l, User $inv, array $a = []) => $model::create($a + ['owner_id' => $this->applicantUser->id, 'business_id' => $l->id, 'investor_id' => $inv->id, 'type' => 'Monetary', 'amount' => 100, 'representation' => 5]);
        $bid(BusinessBids::class, $farm, $amina);
        $big = $bid(BusinessBids::class, $farm, $amina, ['amount' => 500, 'representation' => 10]);
        $bid(BusinessBids::class, $shop, $brian, ['type' => 'Asset', 'amount' => 50]);
        $this->backdate($big, '2026-01-05 12:00:00');
        $bid(AcceptedBids::class, $farm, $amina, ['status' => 'Confirmed', 'paid_in_full' => true, 'amount' => 900]);
        $bid(AcceptedBids::class, $shop, $brian, ['status' => 'verified']);
        $bid(AcceptedBids::class, $shop, $brian, ['status' => 'manager_assigned', 'type' => 'Asset']);

        $pending = '/api/v1/business/business_bids';
        $schema = $this->schema($pending);
        $this->assertSame(['type', 'business', 'investor', 'milestone', 'amount', 'equity', 'targetMet', 'received'], array_keys($schema));
        $this->assertSame(['cash' => 2, 'equipment' => 1], self::counts($schema['type']));
        $this->assertSame(['Farm', 'Shop'], collect($schema['business']['options'])->sortBy('label')->pluck('label')->values()->all());
        $this->assertSame('Brian Kamau', collect($schema['investor']['options'])->firstWhere('value', (string) $brian->id)['label']);
        $this->assertTrue($schema['investor']['searchable']);
        $this->assertSame(['Irrigation' => 2], self::counts($schema['milestone']));
        $this->assertSame([50, 500], [$schema['amount']['min'], $schema['amount']['max']]);

        $amount = fn (array $filters, string $url = '/api/v1/business/business_bids', array $extra = []) => array_map('intval', $this->pick($url, $filters, 'amount', $extra));
        $this->assertSame([50], $amount(['type' => ['equipment']]));
        $this->assertSame([50], $amount(['business' => [(string) $shop->id]]));
        $this->assertSame([50], $amount(['business' => ['Shop']])); // a name, as older links sent it
        $this->assertSame([50], $amount(['investor' => [(string) $brian->id]]));
        $this->assertSame([100, 500], $amount(['milestone' => ['Irrigation']]));
        $this->assertSame([500], $amount(['amount' => '200~']));
        $this->assertSame([500], $amount(['equity' => '6~']));
        $this->assertSame([100, 500], $amount(['targetMet' => 'yes']));
        $this->assertSame([500], $amount(['received' => '2026-01-01~2026-01-31']));

        $confirmed = '/api/v1/business/confirmed_bids';
        $active = $this->schema($confirmed, ['stage' => 'active']);
        $this->assertSame(['type', 'business', 'investor', 'milestone', 'status', 'paidInFull', 'amount', 'equity', 'targetMet', 'received'], array_keys($active));
        $this->assertSame(['Confirmed' => 1, 'verified' => 1], self::counts($active['status']));
        $this->assertSame([900], $amount(['status' => ['Confirmed']], $confirmed, ['stage' => 'active']));
        $this->assertSame([900], $amount(['paidInFull' => 'yes'], $confirmed, ['stage' => 'active']));
        $this->assertSame([100], $amount(['paidInFull' => 'no'], $confirmed, ['stage' => 'active']));

        $verify = $this->schema($confirmed, ['stage' => 'under-verification']);
        $this->assertSame(['under_verification' => 0, 'manager_assigned' => 1, 'equipment_released' => 0], self::counts($verify['status']));
        $this->assertSame([100], $amount(['type' => ['equipment']], $confirmed, ['stage' => 'under-verification']));
    }

    public function test_capital_applications_and_deal_room_describe_and_apply_every_filter(): void
    {
        $fundA = User::factory()->create(['user_type_id' => 5, 'first_name' => 'Acacia', 'last_name' => 'Fund']);
        $fundB = User::factory()->create(['user_type_id' => 5, 'first_name' => 'Baobab', 'last_name' => 'Capital']);
        $offer = fn (User $owner, array $a) => CapitalOffer::create($a + ['user_id' => $owner->id, 'total_capital_available' => 100000, 'per_startup_allocation' => 1000, 'sectors' => ['Agriculture'], 'regions' => ['Kenya'], 'startup_stage' => ['seed'], 'visible' => 1, 'start_date' => '2026-01-01', 'end_date' => '2030-12-31']);
        $apply = fn (CapitalOffer $o, int $status, float $requested) => StartupPitches::create(['capital_id' => $o->id, 'user_id' => $this->applicantUser->id, 'capital_owner_id' => $o->user_id, 'startup_name' => 'Mine', 'contact_person_name' => 'Me', 'contact_person_email' => 'me@example.test', 'sector' => 'Agriculture', 'headquarters_location' => 'Nairobi', 'stage' => 'seed', 'burn_rate' => 0, 'total_amount_requested' => $requested, 'status' => $status]);
        $open = $offer($fundA, ['offer_title' => 'Open Fund', 'sectors' => ['Agriculture', 'Energy']]);
        $soon = $offer($fundB, ['offer_title' => 'Soon Fund', 'sectors' => ['Energy'], 'regions' => ['Uganda'], 'startup_stage' => ['growth'], 'per_startup_allocation' => 5000, 'total_capital_available' => 50000, 'end_date' => now()->addDays(5)->toDateString()]);
        $closed = $offer($fundA, ['offer_title' => 'Closed Fund', 'sectors' => ['Health'], 'end_date' => now()->subDays(5)->toDateString()]);
        $apply($open, StartupPitches::STATUS_PENDING, 500);
        $apply($soon, StartupPitches::STATUS_ACCEPTED, 4000);
        $old = $apply($closed, StartupPitches::STATUS_REJECTED, 100);
        $this->backdate($old, '2026-02-02 08:00:00');

        $url = '/api/v1/capital/my-applications';
        $title = fn (array $filters, array $extra = []) => $this->pick($url, $filters, 'offer_title', $extra);
        $schema = $this->schema($url);
        $this->assertSame(['status', 'timing', 'sector', 'region', 'stage', 'provider', 'requested', 'allocation', 'pool', 'stillOpen', 'applied', 'ends'], array_keys($schema));
        $this->assertSame(['pending' => 1, 'approved' => 1, 'rejected' => 1], self::counts($schema['status']));
        $this->assertSame(['open' => 1, 'closingSoon' => 1, 'upcoming' => 0, 'closed' => 1], self::counts($schema['timing']));
        $this->assertEquals(['Energy' => 2, 'Agriculture' => 1, 'Health' => 1], self::counts($schema['sector']));
        $this->assertSame('Baobab Capital', collect($schema['provider']['options'])->firstWhere('value', (string) $fundB->id)['label']);
        $this->assertSame([100, 4000], [$schema['requested']['min'], $schema['requested']['max']]);
        $this->assertContains('next7Days', $schema['ends']['presets']);

        $this->assertSame(['Soon Fund'], $title(['status' => ['approved']]));
        $this->assertSame(['Soon Fund'], $title(['timing' => ['closingSoon']]));
        $this->assertSame(['Open Fund', 'Soon Fund'], $title(['sector' => ['Energy']]));
        $this->assertSame(['Soon Fund'], $title(['region' => ['Uganda']]));
        $this->assertSame(['Soon Fund'], $title(['stage' => ['growth']]));
        $this->assertSame(['Closed Fund', 'Open Fund'], $title(['provider' => [(string) $fundA->id]]));
        $this->assertSame(['Soon Fund'], $title(['requested' => '1000~']));
        $this->assertSame(['Closed Fund', 'Open Fund'], $title(['allocation' => '~2000']));
        $this->assertSame(['Closed Fund', 'Open Fund'], $title(['pool' => '60000~']));
        $this->assertSame(['Open Fund', 'Soon Fund'], $title(['stillOpen' => 'yes']));
        $this->assertSame(['Closed Fund'], $title(['applied' => '2026-02-01~2026-02-28']));
        $this->assertSame(['Soon Fund'], $title(['ends' => now()->toDateString().'~'.now()->addDays(29)->toDateString()]));

        // the Capital Deal Room (approved only) describes the same filters but status, counted over its own rows
        $deals = $this->schema($url, ['stage' => 'approved']);
        $this->assertSame(array_values(array_diff(array_keys($schema), ['status'])), array_keys($deals));
        $this->assertSame(['Energy' => 1], self::counts($deals['sector']));
        $this->assertSame([], $title(['region' => ['Kenya']], ['stage' => 'approved']));
    }

    public function test_awarded_applications_describe_and_apply_every_filter(): void
    {
        $other = Program::factory()->create(['user_id' => $this->orgUser->id, 'status' => 'published', 'visible' => 1, 'program_title' => 'Scale Up']);
        $award = fn (Program $p, array $a) => ProgramApplication::factory()->create($a + ['program_id' => $p->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'awarded', 'sector' => 'Agriculture', 'headquarters_location' => 'Kilimani, Nairobi, Kenya', 'awarded_amount' => 500, 'total_amount_requested' => 1000, 'match_score' => 60, 'funding_setup_status' => 'not_started']);
        $done = $award($this->program, ['startup_name' => 'Done Co', 'funding_setup_status' => 'completed', 'escrow_funded' => true, 'awarded_amount' => 9000, 'match_score' => 90]);
        $award($this->program, ['startup_name' => 'Waiting Co', 'funding_setup_status' => 'awaiting_applicant_revision', 'sector' => 'Energy']);
        $late = $award($other, ['startup_name' => 'Pwani Fish', 'headquarters_location' => 'Mombasa, Kenya', 'total_amount_requested' => 3000]);
        ProgramMilestone::factory()->create(['app_id' => $done->id]);
        $this->backdate($late, '2026-04-04 10:00:00');

        $url = '/api/v1/programs/my-applications/awarded';
        $name = fn (array $filters) => $this->pick($url, $filters, 'startup_name');
        $schema = $this->schema($url);
        $this->assertSame(['program', 'setup', 'sector', 'location', 'awarded', 'requested', 'match', 'needsAction', 'escrow', 'hasMilestones', 'applied'], array_keys($schema));
        $this->assertSame('Scale Up', collect($schema['program']['options'])->firstWhere('value', (string) $other->id)['label']);
        $this->assertSame(['not_started' => 2, 'in_progress' => 0, 'completed' => 1], self::counts($schema['setup']));
        $this->assertSame(['yes' => 1, 'no' => 2], self::counts($schema['needsAction']));
        $this->assertEquals(['Agriculture' => 2, 'Energy' => 1], self::counts($schema['sector']));

        $this->assertSame(['Pwani Fish'], $name(['program' => [(string) $other->id]]));
        $this->assertSame(['Pwani Fish'], $name(['program' => ['Scale Up']])); // a title, as older links sent it
        $this->assertSame(['Done Co'], $name(['setup' => ['completed']]));
        $this->assertSame(['Waiting Co'], $name(['sector' => ['Energy']]));
        $this->assertSame(['Pwani Fish'], $name(['location' => ['Mombasa, Kenya']]));
        $this->assertSame(['Done Co'], $name(['awarded' => '1000~']));
        $this->assertSame(['Pwani Fish'], $name(['requested' => '2000~']));
        $this->assertSame(['Done Co'], $name(['match' => '80~100']));
        $this->assertSame(['Waiting Co'], $name(['needsAction' => 'yes']));
        $this->assertSame(['Done Co'], $name(['escrow' => 'yes']));
        $this->assertSame(['Done Co'], $name(['hasMilestones' => 'yes']));
        $this->assertSame(['Pwani Fish'], $name(['applied' => '2026-04-01~2026-04-30']));

        // a placeholder sector is not offered as an option
        $award($other, ['startup_name' => 'Vague Co', 'sector' => 'Not specified']);
        $this->assertEquals(['Agriculture' => 2, 'Energy' => 1], self::counts($this->schema($url)['sector']));
    }

    public function test_my_program_applications_describe_filters_and_keep_the_older_page_working(): void
    {
        $multi = Program::factory()->create(['user_id' => $this->orgUser->id, 'status' => 'published', 'visible' => 1, 'program_type' => 'multi_round', 'program_title' => 'Scale Up']);
        $app = fn (Program $p, array $a) => ProgramApplication::factory()->create($a + ['program_id' => $p->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'total_amount_requested' => 1000, 'match_score' => null]);
        $app($multi, ['startup_name' => 'Won', 'status' => 'awarded', 'awarded_amount' => 7000, 'match_score' => 85, 'total_amount_requested' => 8000]);
        $draft = $app($multi, ['startup_name' => 'Draft', 'status' => 'draft']);
        $this->backdate($draft, '2026-05-05 10:00:00');
        $this->application->update(['startup_name' => 'Setup', 'match_score' => 40, 'total_amount_requested' => 1000]); // pending, in $this->round

        $url = '/api/v1/programs/my-applications';
        $name = fn (array $filters) => $this->pick($url, $filters, 'startup_name');
        $schema = $this->schema($url);
        $this->assertSame(['status', 'type', 'program', 'round', 'requested', 'awarded', 'match', 'scored', 'applied'], array_keys($schema));
        $this->assertSame(['draft' => 1, 'pending' => 1, 'approved' => 1, 'rejected' => 0, 'other' => 0], self::counts($schema['status']));
        $this->assertSame(['single' => 1, 'multi' => 2], self::counts($schema['type']));
        $this->assertSame('Scale Up', collect($schema['program']['options'])->firstWhere('value', (string) $multi->id)['label']);
        $this->assertStringEndsWith($this->round->round_name, collect($schema['round']['options'])->firstWhere('value', (string) $this->round->id)['label']);

        $this->assertSame(['Won'], $name(['status' => ['approved']]));
        $this->assertSame(['Setup'], $name(['type' => ['single']]));
        $this->assertSame(['Draft', 'Won'], $name(['program' => [(string) $multi->id]]));
        $this->assertSame(['Draft', 'Won'], $name(['program' => ['Scale Up']])); // the older page sends titles
        $this->assertSame(['Setup'], $name(['round' => [(string) $this->round->id]]));
        $this->assertSame(['Won'], $name(['requested' => '5000~']));
        $this->assertSame(['Won'], $name(['awarded' => '1~']));
        $this->assertSame(['Setup'], $name(['match' => '~50']));
        $this->assertSame(['Setup', 'Won'], $name(['scored' => 'yes']));
        $this->assertSame(['Draft'], $name(['applied' => '2026-05-01~2026-05-31']));

        // the older page's facets keep their values (programs by title)
        $facets = $this->fetchList($url, ['page' => 1, 'per_page' => 5])->json('data.facets');
        $this->assertEquals(['Scale Up' => 2, $this->program->program_title => 1], collect($facets['program'])->pluck('count', 'value')->all());
    }
}
