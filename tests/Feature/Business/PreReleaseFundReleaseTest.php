<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Service\Balance\CurrencyConverter;
use App\Service\LiprMpesa\LiprW2W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A funded business milestone: investor sets pre-release requirements → owner sends documents → investor approves → 75% paid to the owner by LIPR. */
class PreReleaseFundReleaseTest extends TestCase
{
    use RefreshDatabase;

    public array $sent = [];

    public function test_the_pre_release_vote_pays_75_percent_to_the_owner_once(): void
    {
        config(['mail.default' => 'array']);
        DB::table('settings')->insert(['key' => 'platform_lipr_wallet', 'value' => 'PLATFORM-W', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CurrencyConverter::class, new class extends CurrencyConverter { public function __construct() {} public function UsdToKes() { return 130; } public function KesToUsd() { return 1 / 130; } });
        $test = $this;
        $this->app->instance(LiprW2W::class, new class($test) extends LiprW2W {
            public function __construct(private $test) {}
            public function send($amount, string $to, string $from, $m = null, array $o = []) { $this->test->sent[] = [$amount, $to, $from]; return ['success' => true, 'reference' => 'REL-1']; }
        });

        $owner = User::factory()->create(['user_type_id' => 1, 'lipr_wallet' => 'OWNER-W']);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $stranger = User::factory()->create(['user_type_id' => 2]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Kiosks', 'category' => 'Water', 'location' => 'Nairobi', 'investment_needed' => 10000, 'share' => 10, 'active' => 1, 'threshold_met' => 1]);
        $msId = DB::table('milestones')->insertGetId(['user_id' => $owner->id, 'listing_id' => $listing->id, 'title' => 'Kiosk 2', 'amount' => 10000, 'n_o_days' => 14, 'funding_duration' => 30,
            'status' => 'fully_funded', 'is_funded' => 1, 'progress_percentage' => 100, 'funding_collected' => 10000, 'start_date' => now()->toDateString(), 'deadline_date' => now()->addDays(30)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accepted_bids')->insert(['bid_id' => 1, 'ms_id' => $msId, 'investor_id' => $investor->id, 'business_id' => $listing->id, 'owner_id' => $owner->id, 'type' => 'Monetary', 'method' => 'lipr',
            'amount' => 10000, 'representation' => 10, 'status' => 'confirmed', 'paid_in_full' => 1, 'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($stranger)->postJson("/api/v1/milestones/{$msId}/pre-release-requests", ['written_statement' => true])->assertForbidden();
        $this->actingAs($investor)->postJson("/api/v1/milestones/{$msId}/pre-release-requests", ['written_statement' => true])->assertOk();
        $reqId = DB::table('milestone_pre_release_requests')->where('milestone_id', $msId)->value('id');
        $this->assertSame('pre_release_requested', DB::table('milestones')->find($msId)->status);

        $this->actingAs($investor)->postJson("/api/v1/milestones/{$reqId}/pre-release-votes", ['action' => 'approve'])->assertStatus(422); // no documents yet
        $this->actingAs($investor)->postJson("/api/v1/milestones/{$reqId}/pre-release-docs", ['written_statement' => 'x'])->assertNotFound(); // only the owner sends them
        $this->actingAs($owner)->postJson("/api/v1/milestones/{$reqId}/pre-release-docs", ['written_statement' => 'Quotes attached.'])->assertOk();

        $this->actingAs($investor)->postJson("/api/v1/milestones/{$reqId}/pre-release-votes", ['action' => 'approve'])->assertOk()->assertJsonPath('message', 'Pre-release approved.');

        $this->assertSame([[7500, 'OWNER-W', 'PLATFORM-W']], $this->sent); // 75% of KSh 10,000 (business money is KSh, sent as it is)
        $milestone = DB::table('milestones')->find($msId);
        $this->assertSame(1, (int) $milestone->fund_released_75);
        $this->assertSame('in_progress', $milestone->status);
    }

    public function test_a_release_lipr_refuses_is_reported_to_the_voter_not_shown_as_done(): void
    {
        config(['mail.default' => 'array']);
        DB::table('settings')->insert(['key' => 'platform_lipr_wallet', 'value' => 'PLATFORM-W', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(LiprW2W::class, new class extends LiprW2W {
            public function __construct() {}
            public function send($amount, string $to, string $from, $m = null, array $o = []) { return ['success' => false, 'message' => 'Insufficient balance to complete request']; }
        });
        $owner = User::factory()->create(['user_type_id' => 1, 'lipr_wallet' => 'OWNER-W']);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Kiosks', 'category' => 'Water', 'location' => 'Nairobi', 'investment_needed' => 10000, 'share' => 10, 'active' => 1, 'threshold_met' => 1]);
        $msId = DB::table('milestones')->insertGetId(['user_id' => $owner->id, 'listing_id' => $listing->id, 'title' => 'Kiosk 2', 'amount' => 10000, 'n_o_days' => 14, 'funding_duration' => 30,
            'status' => 'fully_funded', 'is_funded' => 1, 'progress_percentage' => 100, 'funding_collected' => 10000, 'start_date' => now()->toDateString(), 'deadline_date' => now()->addDays(30)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accepted_bids')->insert(['bid_id' => 1, 'ms_id' => $msId, 'investor_id' => $investor->id, 'business_id' => $listing->id, 'owner_id' => $owner->id, 'type' => 'Monetary', 'method' => 'lipr',
            'amount' => 10000, 'representation' => 10, 'status' => 'confirmed', 'paid_in_full' => 1, 'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($investor)->postJson("/api/v1/milestones/{$msId}/pre-release-requests", ['written_statement' => true])->assertOk();
        $reqId = DB::table('milestone_pre_release_requests')->where('milestone_id', $msId)->value('id');
        $this->actingAs($owner)->postJson("/api/v1/milestones/{$reqId}/pre-release-docs", ['written_statement' => 'Quotes attached.'])->assertOk();

        $this->actingAs($investor)->postJson("/api/v1/milestones/{$reqId}/pre-release-votes", ['action' => 'approve'])
            ->assertOk()->assertJsonPath('release_failed', true)
            ->assertJsonPath('message', 'Your vote is saved, but the 75% release could not be sent yet: Transfer could not be completed: Insufficient balance to complete request. Please try again.');
        $this->assertSame(0, (int) DB::table('milestones')->find($msId)->fund_released_75);
    }
}
