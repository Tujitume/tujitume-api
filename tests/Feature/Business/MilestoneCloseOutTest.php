<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Service\Balance\CurrencyConverter;
use App\Service\LiprMpesa\LiprW2W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** After the 75%: the owner's mid-milestone update → investors approve → last 25% paid; completion proof → investors approve → milestone done. */
class MilestoneCloseOutTest extends TestCase
{
    use RefreshDatabase;

    public array $sent = [];

    public function test_the_mid_milestone_pays_the_last_25_percent_and_final_approval_closes_it(): void
    {
        Storage::fake('s3');
        config(['mail.default' => 'array']);
        DB::table('settings')->insert(['key' => 'platform_lipr_wallet', 'value' => 'PLATFORM-W', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CurrencyConverter::class, new class extends CurrencyConverter { public function __construct() {} public function UsdToKes() { return 130; } public function KesToUsd() { return 1 / 130; } });
        $test = $this;
        $this->app->instance(LiprW2W::class, new class($test) extends LiprW2W {
            public function __construct(private $test) {}
            public function send($amount, string $to, string $from, $m = null, array $o = []) { $this->test->sent[] = [$amount, $to]; return ['success' => true, 'reference' => 'REL']; }
        });

        $owner = User::factory()->create(['user_type_id' => 1, 'lipr_wallet' => 'OWNER-W']);
        $investor = User::factory()->create(['user_type_id' => 2]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Kiosks', 'category' => 'Water', 'location' => 'Nairobi', 'investment_needed' => 10000, 'share' => 10, 'active' => 1, 'threshold_met' => 1]);
        $msId = DB::table('milestones')->insertGetId(['user_id' => $owner->id, 'listing_id' => $listing->id, 'title' => 'Kiosk 2', 'amount' => 10000, 'n_o_days' => 14, 'funding_duration' => 30,
            'status' => 'in_progress', 'is_funded' => 1, 'progress_percentage' => 100, 'funding_collected' => 10000, 'fund_released_75' => 1,
            'start_date' => now()->toDateString(), 'deadline_date' => now()->addDays(30)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accepted_bids')->insert(['bid_id' => 1, 'ms_id' => $msId, 'investor_id' => $investor->id, 'business_id' => $listing->id, 'owner_id' => $owner->id, 'type' => 'Monetary', 'method' => 'lipr',
            'amount' => 10000, 'representation' => 10, 'status' => 'confirmed', 'paid_in_full' => 1, 'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $file = fn ($name, $mime) => UploadedFile::fake()->create($name, 10, $mime);

        // Proof before the money is out is refused
        $this->actingAs($owner)->postJson('/api/v1/milestones/final-approval/document', ['milestone_id' => $msId, 'receipt' => $file('r.pdf', 'application/pdf')])->assertStatus(422);

        $this->actingAs($owner)->postJson('/api/v1/milestones/mid-milestones', ['milestone_id' => $msId, 'progress_statement' => 'Half built.', 'timeline_forecast' => '2 weeks',
            'photos' => UploadedFile::fake()->image('site.png'), 'receipt' => $file('r.pdf', 'application/pdf'), 'work_logs' => $file('w.pdf', 'application/pdf')])->assertOk();
        $midId = DB::table('mid_milestones')->where('milestone_id', $msId)->value('id');
        $this->actingAs($investor)->postJson('/api/v1/milestones/mid-milestones/vote', ['mid_milestone_id' => $midId, 'vote' => 'approve'])->assertOk();

        $this->assertSame([[2500, 'OWNER-W']], $this->sent); // 25% of KSh 10,000 (business money is KSh)
        $this->assertSame(1, (int) DB::table('milestones')->find($msId)->fund_released);

        $this->actingAs($owner)->postJson('/api/v1/milestones/final-approval/document', ['milestone_id' => $msId, 'receipt' => $file('final.pdf', 'application/pdf')])->assertOk();
        $execId = DB::table('milestone_execution_documents')->where('milestone_id', $msId)->value('id');
        $this->actingAs($investor)->postJson('/api/v1/milestones/final-approval/votes', ['execution_id' => $execId, 'action' => 'approve'])->assertOk();

        $this->assertSame('done', DB::table('milestones')->find($msId)->status);
        $this->assertCount(1, $this->sent); // final approval moves no more money
    }
}
