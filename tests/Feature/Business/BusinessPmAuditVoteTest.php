<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Investors choose the project manager who audits a business milestone: investors only, a suggested PM only, once. */
class BusinessPmAuditVoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_investors_pick_a_suggested_pm_once(): void
    {
        config(['mail.default' => 'array']);
        $owner = User::factory()->create(['user_type_id' => 1]);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $stranger = User::factory()->create(['user_type_id' => 2]);
        $pm = User::factory()->create(['user_type_id' => 3]);
        $otherPm = User::factory()->create(['user_type_id' => 3]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Kiosks', 'category' => 'Water', 'location' => 'Nairobi', 'investment_needed' => 10000, 'share' => 10, 'active' => 1]);
        $msId = DB::table('milestones')->insertGetId(['user_id' => $owner->id, 'listing_id' => $listing->id, 'title' => 'Kiosk 2', 'amount' => 10000, 'n_o_days' => 14, 'funding_duration' => 30,
            'status' => 'in_pr_audit', 'is_funded' => 1, 'progress_percentage' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accepted_bids')->insert(['bid_id' => 1, 'ms_id' => $msId, 'investor_id' => $investor->id, 'business_id' => $listing->id, 'owner_id' => $owner->id, 'type' => 'Monetary', 'method' => 'lipr',
            'amount' => 10000, 'representation' => 10, 'status' => 'confirmed', 'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $reqId = DB::table('milestone_pre_release_requests')->insertGetId(['milestone_id' => $msId, 'investor_id' => $investor->id, 'weight' => 10000, 'status' => 'pm_audit', 'created_at' => now(), 'updated_at' => now()]);
        $auditId = DB::table('p_m_audits')->insertGetId(['type' => 'pre_release', 'pr_request_id' => $reqId, 'milestone_id' => $msId, 'candidate_pm_ids' => json_encode([$pm->id]), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($stranger)->postJson('/api/v1/milestones/pre-release/pm-vote', ['audit_id' => $auditId, 'voted_pm_id' => $pm->id])->assertForbidden();
        $this->actingAs($investor)->postJson('/api/v1/milestones/pre-release/pm-vote', ['audit_id' => $auditId, 'voted_pm_id' => $otherPm->id])->assertStatus(422);
        $this->actingAs($investor)->postJson('/api/v1/milestones/pre-release/pm-vote', ['audit_id' => $auditId, 'voted_pm_id' => $pm->id])->assertOk();

        $this->assertSame($pm->id, (int) DB::table('p_m_audits')->find($auditId)->assigned_pm_id); // the only investor voted: chosen
        $this->assertTrue(DB::table('notifications')->where('receiver_id', $pm->id)->exists());
    }
}
