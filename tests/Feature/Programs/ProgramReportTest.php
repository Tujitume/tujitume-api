<?php

namespace Tests\Feature\Programs;

use App\Models\Programs\Disbursement;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Models\Auth\User;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\RoundRequiredDocument;
use App\Models\ReviewerOrder;

/** The owner's applicants list, money ledger and activity feed on the program page. */
class ProgramReportTest extends ProgramTestCase
{
    private function report(string $part, array $query = [])
    {
        return $this->actingAsOrgOwner()->getJson("/api/v1/programs/{$this->program->id}/overview/{$part}?".http_build_query($query));
    }

    private function makeApp(array $o = []): ProgramApplication
    {
        return ProgramApplication::factory()->create($o + [
            'program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id,
            'current_round_id' => $this->round->id, 'status' => 'pending', 'round_status' => 'submitted',
        ]);
    }

    private function score(ProgramApplication $a): void
    {
        ApplicationScore::factory()->create(['application_id' => $a->id, 'round_id' => $this->round->id, 'reviewer_id' => $this->reviewerUser->id]);
    }

    // ── access ──
    public function test_only_the_program_team_can_read_the_reports(): void
    {
        foreach (['applications', 'wallet', 'activity'] as $part) {
            $this->actingAs($this->applicantUser, 'sanctum')->getJson("/api/v1/programs/{$this->program->id}/overview/{$part}")->assertForbidden();
            $this->report($part)->assertOk();
        }
    }

    // ── applications ──
    public function test_applications_are_paged_searchable_and_filterable_by_round_and_review(): void
    {
        $reviewed = $this->makeApp(['startup_name' => 'Zebra Farms', 'sector' => 'Agri']);
        $this->score($reviewed);
        $this->makeApp(['startup_name' => 'Acme Tech', 'sector' => 'Tech', 'status' => 'rejected']);

        $all = $this->report('applications', ['page' => 1, 'per_page' => 2]);
        $all->assertOk();
        $this->assertCount(2, $all->json('data.items'));
        $this->assertSame(3, $all->json('data.pagination.total')); // setUp's application + the two above
        $this->assertSame(3, $all->json('data.stats.total'));
        $this->assertSame(1, $all->json('data.stats.reviewed'));

        $this->assertSame(['Zebra Farms'], collect($this->report('applications', ['page' => 1, 'search' => 'zebra'])->json('data.items'))->pluck('startup_name')->all());
        $this->assertSame(['Acme Tech'], collect($this->report('applications', ['page' => 1, 'filters' => ['sector' => ['Tech']]])->json('data.items'))->pluck('startup_name')->all());

        $reviewedOnly = $this->report('applications', ['page' => 1, 'filters' => ['review' => ['reviewed']]]);
        $this->assertSame(['Zebra Farms'], collect($reviewedOnly->json('data.items'))->pluck('startup_name')->all());
        $this->assertTrue($reviewedOnly->json('data.items.0.reviewed'));
        $this->assertSame(2, $this->report('applications', ['page' => 1, 'filters' => ['review' => ['not_reviewed']]])->json('data.pagination.total'));

        $this->assertSame(3, $this->report('applications', ['page' => 1, 'filters' => ['round' => [$this->round->id]]])->json('data.pagination.total'));
        $this->assertSame(0, $this->report('applications', ['page' => 1, 'filters' => ['round' => [999999]]])->json('data.pagination.total'));
    }

    public function test_drafts_and_other_programs_are_not_listed_and_export_returns_every_match(): void
    {
        $this->makeApp(['status' => 'draft', 'startup_name' => 'Hidden Draft']);
        foreach (range(1, 12) as $i) {
            $this->makeApp(['startup_name' => "Biz {$i}"]);
        }

        $export = $this->report('applications', ['export' => 1, 'per_page' => 5]);
        $names = collect($export->json('data.items'))->pluck('startup_name');
        $this->assertCount(13, $names);                       // setUp's application + 12
        $this->assertNotContains('Hidden Draft', $names->all());
        $this->assertNull($export->json('data.pagination'));
        $this->assertArrayHasKey('applicant_image', $export->json('data.items.0'));
    }

    // ── wallet ──
    public function test_the_wallet_ledger_lists_payments_with_totals_by_who_was_paid(): void
    {
        $milestone = ProgramMilestone::factory()->create(['app_id' => $this->application->id]);
        Disbursement::create(['milestone_id' => $milestone->id, 'wallet_id' => $this->wallet->id, 'recipient_type' => 'supplier', 'amount' => 4000, 'currency' => 'KES', 'payment_method' => 'mpesa_mobile', 'status' => 'completed', 'payment_reference' => 'MPESA-1', 'disbursed_at' => now()]);
        Disbursement::create(['milestone_id' => $milestone->id, 'wallet_id' => $this->wallet->id, 'recipient_type' => 'supplier', 'amount' => 1000, 'currency' => 'KES', 'payment_method' => 'mpesa_mobile', 'status' => 'pending']);
        ReviewerOrder::create(['program_id' => $this->program->id, 'round_id' => $this->round->id, 'organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'order_type' => 'round_review', 'fee_amount' => 700, 'currency' => 'KES', 'work_status' => 'approved', 'acceptance_status' => 'accepted', 'payment_status' => 'completed', 'paid_at' => now()]);

        $r = $this->report('wallet', ['page' => 1, 'per_page' => 10]);
        $r->assertOk();
        $this->assertCount(3, $r->json('data.items'));
        $this->assertSame(3, $r->json('data.pagination.total'));
        $this->assertEquals(4000, $r->json('data.analytics.suppliers.paid'));
        $this->assertEquals(1000, $r->json('data.analytics.suppliers.pending'));
        $this->assertEquals(700, $r->json('data.analytics.business_coaches.paid'));
        $this->assertEquals(4700, $r->json('data.analytics.paid_total'));
        $this->assertFalse($r->json('data.analytics.deposits_itemised'));
        $this->assertEquals(100000, $r->json('data.wallet.balance'));

        $this->assertSame(['MPESA-1'], collect($this->report('wallet', ['page' => 1, 'search' => 'mpesa-1'])->json('data.items'))->pluck('reference')->all());
        $this->assertSame(1, $this->report('wallet', ['page' => 1, 'filters' => ['kind' => ['business_coach']]])->json('data.pagination.total'));
        $this->assertSame(1, $this->report('wallet', ['page' => 1, 'filters' => ['status' => ['pending']]])->json('data.pagination.total'));
        $amounts = collect($this->report('wallet', ['page' => 1, 'sort' => '-amount'])->json('data.items'))->pluck('amount')->all();
        $this->assertEquals([4000, 1000, 700], $amounts);
    }

    // ── activity ──
    public function test_the_activity_feed_says_what_happened_newest_first(): void
    {
        $a = $this->makeApp(['startup_name' => 'Active Biz']);
        $this->score($a);

        $r = $this->report('activity', ['page' => 1, 'per_page' => 50]);
        
        $items = collect($r->json('data.items'));
        $this->assertContains('program_created', $items->pluck('type')->all());
        $this->assertContains('application_submitted', $items->pluck('type')->all());
        $this->assertContains('application_scored', $items->pluck('type')->all());
        $this->assertContains('round_created', $items->pluck('type')->all());

        $times = $items->pluck('at')->all();
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);

        $this->assertSame(['application_scored'], collect($this->report('activity', ['page' => 1, 'filters' => ['group' => ['reviews']]])->json('data.items'))->pluck('type')->unique()->values()->all());
        $this->assertTrue(collect($this->report('activity', ['page' => 1, 'search' => 'active biz'])->json('data.items'))->every(fn ($e) => str_contains(strtolower($e['subject'].$e['actor']), 'active biz')));
    }

    // ── described filters (filter_schema) ──
    private function schemaOf(string $part, array $query = []): \Illuminate\Support\Collection
    {
        return collect($this->report($part, ['page' => 1, 'per_page' => 10] + $query)->assertOk()->json('data.filter_schema'))->keyBy('key');
    }

    private function total(string $part, array $filters): int
    {
        return (int) $this->report($part, ['page' => 1, 'per_page' => 10, 'filters' => $filters])->assertOk()->json('data.pagination.total');
    }

    public function test_the_applications_tab_describes_and_applies_every_filter(): void
    {
        // setUp's application: pending, the applicant's KYC verified. Two more by an applicant without KYC.
        $this->application->update(['total_amount_requested' => 3000, 'awarded_amount' => null, 'match_score' => null, 'average_score' => null,
            'sector' => 'Tech', 'stage' => 'idea', 'headquarters_location' => 'Kisumu, Kenya']);
        $other = User::factory()->create(['user_type_id' => 1]);
        $awarded = $this->makeApp(['user_id' => $other->id, 'startup_name' => 'Awarded Co', 'status' => 'awarded', 'awarded_amount' => 5000, 'total_amount_requested' => 8000,
            'match_score' => 80, 'average_score' => 72, 'sector' => 'Agri', 'stage' => 'growth', 'headquarters_location' => 'Westlands, Nairobi, Kenya']);
        $this->score($awarded);
        RoundRequiredDocument::create(['application_id' => $awarded->id, 'round_id' => $this->round->id, 'document_type' => 'id', 'file_path' => 'docs/a.pdf']);
        $this->makeApp(['user_id' => $other->id, 'startup_name' => 'Small Co', 'status' => 'rejected', 'total_amount_requested' => 1000, 'match_score' => 30,
            'average_score' => 20, 'sector' => 'Not specified', 'headquarters_location' => 'Mombasa, Kenya']);

        $schema = $this->schemaOf('applications');
        $this->assertSame(['status', 'round', 'review', 'stage', 'sector', 'location', 'match', 'match_tier', 'score', 'requested', 'awarded', 'documents', 'kyc', 'applied'], $schema->keys()->all());
        $tiers = collect($schema['match_tier']['options'])->pluck('count', 'value')->all(); // 80 is an Ideal Match, 30 Needs Revision
        $this->assertSame(1, $tiers['ideal'] ?? null);
        $this->assertGreaterThanOrEqual(1, $tiers['needs_revision'] ?? 0);
        $this->assertSame('grant:view.reports.applications.filterRound', $schema['round']['label']);
        $this->assertStringStartsWith('Round 1', $schema['round']['options'][0]['label']);
        $this->assertSame(3, $schema['round']['options'][0]['count']);
        $this->assertEquals(['reviewed' => 1, 'not_reviewed' => 2], collect($schema['review']['options'])->pluck('count', 'value')->all());
        $this->assertSame('grant:view.reports.applications.reviewed', $schema['review']['options'][0]['label']);
        $this->assertNotContains('Not specified', collect($schema['sector']['options'])->pluck('value')->all()); // placeholders are not offered
        $this->assertContains('Nairobi, Kenya', collect($schema['location']['options'])->pluck('value')->all());
        $this->assertSame(['min' => 1000, 'max' => 8000], array_intersect_key($schema['requested'], ['min' => 1, 'max' => 1]));
        $this->assertSame('yes', $schema['awarded']['options'][0]['value']);
        $this->assertSame(1, $schema['awarded']['options'][0]['count']);
        $this->assertSame(['value' => 'yes', 'count' => 1], $schema['documents']['options'][0]);
        $this->assertSame(['value' => 'yes', 'count' => 1], $schema['kyc']['options'][0]);
        $this->assertContains('last30Days', $schema['applied']['presets']);

        $this->assertSame(1, $this->total('applications', ['status' => ['awarded']]));
        $this->assertSame(1, $this->total('applications', ['location' => ['Nairobi, Kenya']]));
        $this->assertSame(1, $this->total('applications', ['stage' => ['growth']]));
        $this->assertSame(1, $this->total('applications', ['sector' => ['Agri']]));
        $this->assertSame(1, $this->total('applications', ['match' => '50~']));
        $this->assertSame(1, $this->total('applications', ['score' => '~30']));
        $this->assertSame(2, $this->total('applications', ['requested' => '2000~10000']));
        $this->assertSame(1, $this->total('applications', ['awarded' => 'yes']));
        $this->assertSame(2, $this->total('applications', ['awarded' => 'no']));
        $this->assertSame(1, $this->total('applications', ['documents' => 'yes']));
        $this->assertSame(2, $this->total('applications', ['documents' => 'no']));
        $this->assertSame(1, $this->total('applications', ['kyc' => 'yes']));
        $this->assertSame(2, $this->total('applications', ['kyc' => 'no']));
        $this->assertSame(3, $this->total('applications', ['applied' => now()->subDay()->toDateString().'~'.now()->toDateString()]));
        $this->assertSame(0, $this->total('applications', ['applied' => '2000-01-01~2000-12-31']));
        // the counts follow the other filters
        $this->assertEquals(['reviewed' => 1], collect($this->schemaOf('applications', ['filters' => ['documents' => 'yes']])['review']['options'])->pluck('count', 'value')->filter()->all());
        // the old facets are still sent
        $this->assertNotNull($this->report('applications', ['page' => 1])->json('data.facets.review'));
    }

    public function test_the_wallet_ledger_describes_and_applies_every_filter(): void
    {
        $milestone = ProgramMilestone::factory()->create(['app_id' => $this->application->id]);
        Disbursement::create(['milestone_id' => $milestone->id, 'wallet_id' => $this->wallet->id, 'recipient_type' => 'business_owner', 'amount' => 4000, 'currency' => 'KES', 'payment_method' => 'mpesa_mobile', 'status' => 'completed', 'disbursed_at' => now()->subDays(3)]);
        Disbursement::create(['milestone_id' => $milestone->id, 'wallet_id' => $this->wallet->id, 'recipient_type' => 'supplier', 'amount' => 1000, 'currency' => 'KES', 'payment_method' => 'mpesa_mobile', 'status' => 'failed']);
        ReviewerOrder::create(['program_id' => $this->program->id, 'round_id' => $this->round->id, 'organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'order_type' => 'round_review', 'fee_amount' => 700, 'currency' => 'USD', 'work_status' => 'approved', 'acceptance_status' => 'accepted', 'payment_status' => 'unpaid']);

        $schema = $this->schemaOf('wallet');
        $this->assertSame(['kind', 'status', 'recipient', 'currency', 'amount', 'business_linked', 'date'], $schema->keys()->all());
        $kinds = collect($schema['kind']['options'])->keyBy('value');
        $this->assertSame(['supplier', 'business', 'business_coach'], $kinds->keys()->all());
        $this->assertSame(['value' => 'business_coach', 'count' => 1, 'label' => 'grant:view.reports.wallet.kind.business_coach'], $kinds['business_coach']);
        $this->assertEquals(['paid' => 1, 'pending' => 1, 'failed' => 1], collect($schema['status']['options'])->pluck('count', 'value')->all());
        $this->assertContains(trim($this->reviewerUser->first_name.' '.$this->reviewerUser->last_name), collect($schema['recipient']['options'])->pluck('value')->all());
        $this->assertTrue($schema['recipient']['searchable']);
        $this->assertSame([700, 4000], [$schema['amount']['min'], $schema['amount']['max']]);
        $this->assertEquals(['yes' => 2, 'no' => 1], collect($schema['business_linked']['options'])->pluck('count', 'value')->all());

        $coach = trim($this->reviewerUser->first_name.' '.$this->reviewerUser->last_name);
        $this->assertSame(1, $this->total('wallet', ['recipient' => [$coach]]));
        $this->assertSame(1, $this->total('wallet', ['currency' => ['USD']]));
        $this->assertSame(2, $this->total('wallet', ['amount' => '~1000']));
        $this->assertSame(1, $this->total('wallet', ['amount' => '2000~']));
        $this->assertSame(2, $this->total('wallet', ['business_linked' => 'yes']));
        $this->assertSame(1, $this->total('wallet', ['business_linked' => 'no']));
        $this->assertSame(1, $this->total('wallet', ['status' => ['failed']]));
        $this->assertSame(1, $this->total('wallet', ['date' => now()->subDays(4)->toDateString().'~'.now()->subDays(2)->toDateString()]));
        // bounds follow the other filters
        $this->assertSame([4000, 4000], [$this->schemaOf('wallet', ['filters' => ['kind' => ['business']]])['amount']['min'], $this->schemaOf('wallet', ['filters' => ['kind' => ['business']]])['amount']['max']]);
    }

    public function test_the_activity_feed_describes_and_applies_every_filter(): void
    {
        $a = $this->makeApp(['startup_name' => 'Active Biz']);
        $this->score($a);
        ReviewerOrder::create(['program_id' => $this->program->id, 'round_id' => $this->round->id, 'organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'order_type' => 'round_review', 'fee_amount' => 700, 'currency' => 'KES', 'work_status' => 'approved', 'acceptance_status' => 'accepted', 'payment_status' => 'completed', 'paid_at' => now()]);

        $schema = $this->schemaOf('activity');
        $this->assertSame(['group', 'round', 'coach', 'business', 'amount', 'date'], $schema->keys()->all());
        $this->assertSame('grant:view.reports.activity.groups.reviews', collect($schema['group']['options'])->firstWhere('value', 'reviews')['label']);
        $this->assertSame('app:reviewer', $schema['coach']['label']);
        $coach = trim($this->reviewerUser->first_name.' '.$this->reviewerUser->last_name);
        $this->assertSame([['value' => $coach, 'count' => 1]], $schema['coach']['options']);
        $this->assertContains('Active Biz', collect($schema['business']['options'])->pluck('value')->all());
        $this->assertSame(['min' => 700, 'max' => 700], array_intersect_key($schema['amount'], ['min' => 1, 'max' => 1]));

        $this->assertSame(1, $this->total('activity', ['coach' => [$coach]]));
        $this->assertSame(2, $this->total('activity', ['business' => ['Active Biz']])); // applied + scored
        $this->assertSame(1, $this->total('activity', ['amount' => '500~1000']));
        $this->assertSame(1, $this->total('activity', ['round' => ['1'], 'group' => ['reviews']]));
        $this->assertSame(0, $this->total('activity', ['date' => '2000-01-01~2000-01-31']));
    }
}
