<?php

namespace Tests\Feature\Programs;

use App\Models\Programs\Disbursement;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\Rounds\ApplicationScore;
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
}
