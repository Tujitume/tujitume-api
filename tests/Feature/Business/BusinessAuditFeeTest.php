<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Service\Balance\CurrencyConverter;
use App\Service\LiprMpesa\LiprW2W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** DECISION-9 on business milestones: the PM's fee is held from the investors' money for the milestone, paid on the report, taken off the next release. */
class BusinessAuditFeeTest extends TestCase
{
    use RefreshDatabase;

    public array $sent = [];

    private function world(string $type, array $milestone = []): array
    {
        config(['mail.default' => 'array']);
        DB::table('settings')->insert(['key' => 'platform_lipr_wallet', 'value' => 'PLATFORM-W', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CurrencyConverter::class, new class extends CurrencyConverter { public function __construct() {} public function UsdToKes() { return 130; } public function KesToUsd() { return 1 / 130; } });
        $test = $this;
        $this->app->instance(LiprW2W::class, new class($test) extends LiprW2W {
            public function __construct(private $test) {}
            public function send($amount, string $to, string $from, $m = null, array $o = []) { $this->test->sent[] = [$amount, $to, $from]; return ['success' => true, 'reference' => 'R'.count($this->test->sent)]; }
        });
        $owner = User::factory()->create(['user_type_id' => 1, 'lipr_wallet' => 'OWNER-W']);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $pm = User::factory()->create(['user_type_id' => 3, 'lipr_wallet' => 'PM-W']);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Kiosks', 'category' => 'Water', 'location' => 'Nairobi', 'investment_needed' => 10000, 'share' => 10, 'active' => 1]);
        $msId = DB::table('milestones')->insertGetId($milestone + ['user_id' => $owner->id, 'listing_id' => $listing->id, 'title' => 'Kiosk 2', 'amount' => 10000, 'n_o_days' => 14, 'funding_duration' => 30,
            'status' => 'in_pr_audit', 'is_funded' => 1, 'progress_percentage' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accepted_bids')->insert(['bid_id' => 1, 'ms_id' => $msId, 'investor_id' => $investor->id, 'business_id' => $listing->id, 'owner_id' => $owner->id, 'type' => 'Monetary', 'method' => 'lipr',
            'amount' => 10000, 'representation' => 10, 'status' => 'confirmed', 'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $reqId = DB::table('milestone_pre_release_requests')->insertGetId(['milestone_id' => $msId, 'investor_id' => $investor->id, 'weight' => 10000, 'status' => 'pm_audit', 'created_at' => now(), 'updated_at' => now()]);
        $auditId = DB::table('p_m_audits')->insertGetId(['type' => $type, 'pr_request_id' => $reqId, 'milestone_id' => $msId, 'assigned_pm_id' => $pm->id, 'status' => 'pending',
            'candidate_pm_ids' => json_encode([$pm->id]), 'created_at' => now(), 'updated_at' => now()]);

        return [$pm, $auditId, $msId];
    }

    public function test_the_fee_is_held_paid_on_the_report_and_taken_off_the_release(): void
    {
        [$pm, $auditId, $msId] = $this->world('pre_release');

        // 75% of KSh 10,000 = KSh 7,500 held; a fee above 20% of it (1,500) is refused
        $this->actingAs($pm)->postJson('/api/v1/milestones/audit-request', ['audit_id' => $auditId, 'action' => 'accept', 'fee' => 500000])->assertStatus(422);
        $this->assertSame('pending', DB::table('p_m_audits')->find($auditId)->status);

        $this->actingAs($pm)->postJson('/api/v1/milestones/audit-request', ['audit_id' => $auditId, 'action' => 'accept', 'fee' => 200])->assertOk();
        $this->assertSame('held', DB::table('p_m_audits')->find($auditId)->fee_status);

        $this->actingAs($pm)->postJson("/api/v1/milestones/audits/{$auditId}/review", ['audit_id' => $auditId, 'action' => 'approved', 'notes' => 'Quotes and site match the plan.'])->assertOk();

        $this->assertSame([200, 'PM-W', 'PLATFORM-W'], $this->sent[0]);                 // the PM's fee from the held money
        $this->assertSame([7300, 'OWNER-W', 'PLATFORM-W'], $this->sent[1]);               // KSh 7,500 − 200 to the owner
        $this->assertSame('deducted', DB::table('p_m_audits')->find($auditId)->fee_status);
        $this->assertSame(1, (int) DB::table('milestones')->find($msId)->fund_released_75);
        $this->assertTrue(DB::table('transactions')->where('type', 'pm_audit_fee')->where('user_id', $pm->id)->where('direction', 'credit')->exists());
    }

    public function test_no_fee_can_be_held_at_final_approval(): void
    {
        [$pm, $auditId] = $this->world('final_approval', ['fund_released_75' => 1, 'fund_released' => 1, 'status' => 'in_final_audit']);
        $this->actingAs($pm)->postJson('/api/v1/milestones/audit-request', ['audit_id' => $auditId, 'action' => 'accept', 'fee' => 1000])->assertStatus(422);
        $this->actingAs($pm)->postJson('/api/v1/milestones/audit-request', ['audit_id' => $auditId, 'action' => 'accept', 'fee' => 0])->assertOk();
    }
}
