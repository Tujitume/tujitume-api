<?php

use App\Models\Auth\User;
use App\Service\Pending\PendingTasks;
use App\Service\Pending\Scenario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Programs\ProgramTestCase;

uses(ProgramTestCase::class);

function pendingNow($t, User $user)
{
    Cache::flush();

    return $t->actingAs($user, 'sanctum')->getJson('/api/v1/pending-tasks?fresh=1');
}
function typesOf($res): array
{
    return collect($res->json('data.groups'))->flatMap(fn ($g) => collect($g['items'])->pluck('type'))->all();
}

// ── the plug-in contract: a new feature adds a class and these keep it honest ─────────────────────────────────────

it('discovers every scenario and each one follows the contract', function () {
    $registry = PendingTasks::registry();
    expect($registry)->not->toBeEmpty();
    $files = collect(glob(app_path('Service/Pending/Scenarios/*/*.php')))->count();
    expect(count($registry))->toBe($files); // every file was found: no two share a key

    foreach ($registry as $key => $scenario) {
        expect($scenario)->toBeInstanceOf(Scenario::class);
        expect($key)->toMatch('/^[a-z]+\.[a-z_]+$/');
        $types = $scenario->userTypes();
        expect($types === null || (is_array($types) && $types && array_diff($types, [1, 2, 3, 4, 5, 6, 7]) === []))->toBeTrue("{$key} lists user types that do not exist");
    }
});

it('only uses groups the drawer knows', function () {
    foreach (glob(app_path('Service/Pending/Scenarios/*/*.php')) as $file) {
        preg_match_all("/(?:'group' => |->group\()'([a-z]+)'/", file_get_contents($file), $m);
        foreach ($m[1] as $group) {
            expect(in_array($group, Scenario::GROUPS, true))->toBeTrue(basename($file)." uses an unknown group '{$group}'");
        }
    }
});

it('runs every scenario for every user type without a single failure (failures are otherwise swallowed)', function () {
    Log::spy();
    foreach ([1, 2, 3, 4, 5, 6] as $type) {
        $user = User::factory()->create(['user_type_id' => $type, 'organization_id' => $type === 4 ? $this->organization->id : null]);
        $this->createVerifiedKyc($user, 'entrepreneur');
        pendingNow($this, $user)->assertOk();
    }
    Log::shouldNotHaveReceived('warning', fn ($message) => $message === 'Pending scenario failed');
});

// ── the scenarios that complete the picture ─────────────────────────────────────────────────────────────────────

it('asks investors for the votes that are open to them and stops once they voted', function () {
    $investor = User::factory()->create(['user_type_id' => 2]);
    $this->createVerifiedKyc($investor, 'entrepreneur');
    $listing = DB::table('listings')->insertGetId(['user_id' => $this->applicantUser->id, 'name' => 'Maize Co', 'category' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    $milestone = DB::table('milestones')->insertGetId(['user_id' => $this->applicantUser->id, 'listing_id' => $listing, 'title' => 'Buy mill', 'amount' => 1000, 'n_o_days' => 10, 'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('accepted_bids')->insert(['bid_id' => 1, 'ms_id' => $milestone, 'date' => now()->toDateString(), 'investor_id' => $investor->id, 'business_id' => $listing, 'owner_id' => $this->applicantUser->id, 'amount' => 500, 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);

    // mid-milestone report waiting for investors
    $mid = DB::table('mid_milestones')->insertGetId(['milestone_id' => $milestone, 'created_by' => $this->applicantUser->id, 'progress_statement' => 'x', 'progress_percent' => 40, 'timeline_forecast' => 'on time', 'status' => 'pending_investor_review', 'created_at' => now(), 'updated_at' => now()]);
    expect(typesOf(pendingNow($this, $investor)))->toContain('investor.mid_vote');
    DB::table('mid_milestone_votes')->insert(['mid_milestone_id' => $mid, 'investor_id' => $investor->id, 'vote' => 'approve', 'weight' => 1, 'created_at' => now(), 'updated_at' => now()]);
    expect(typesOf(pendingNow($this, $investor)))->not->toContain('investor.mid_vote');

    // final proof: only while its voting window is open
    $exec = DB::table('milestone_execution_documents')->insertGetId(['milestone_id' => $milestone, 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now()]);
    expect(typesOf(pendingNow($this, $investor)))->not->toContain('investor.final_vote');
    DB::table('votes')->insert(['type' => 'final_approval', 'reference_id' => $exec, 'starts_at' => now(), 'ends_at' => now()->addDays(3), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    expect(typesOf(pendingNow($this, $investor)))->toContain('investor.final_vote');
    // someone else's investment does not ask me
    $stranger = User::factory()->create(['user_type_id' => 2]);
    $this->createVerifiedKyc($stranger, 'entrepreneur');
    expect(typesOf(pendingNow($this, $stranger)))->not->toContain('investor.final_vote')->not->toContain('investor.mid_vote');

    // RMEP: only investors listed as eligible, only while active, only until they voted
    $rmep = DB::table('milestone_rmeps')->insertGetId(['milestone_id' => $milestone, 'milestone_name' => 'Plan', 'description' => 'd', 'voting_deadline' => now()->addDays(2), 'status' => 'active', 'eligible_voters' => json_encode([$investor->id]), 'created_at' => now(), 'updated_at' => now()]);
    expect(typesOf(pendingNow($this, $investor)))->toContain('investor.rmep_vote')->and(typesOf(pendingNow($this, $stranger)))->not->toContain('investor.rmep_vote');
    DB::table('rmep_votes')->insert(['rmep_id' => $rmep, 'investor_id' => $investor->id, 'vote' => 'approve', 'weight' => 1, 'created_at' => now(), 'updated_at' => now()]);
    expect(typesOf(pendingNow($this, $investor)))->not->toContain('investor.rmep_vote');
});

it('asks a business owner for mid-milestone documents only once 30 percent of the time has passed', function () {
    $listing = DB::table('listings')->insertGetId(['user_id' => $this->applicantUser->id, 'name' => 'Maize Co', 'category' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    $base = ['user_id' => $this->applicantUser->id, 'listing_id' => $listing, 'title' => 'Buy mill', 'amount' => 1000, 'n_o_days' => 10, 'status' => 'in_progress', 'fund_released_75' => 1, 'mid_milestone_started' => 0, 'created_at' => now(), 'updated_at' => now()];

    $id = DB::table('milestones')->insertGetId($base + ['exec_deadline_date' => now()->addDays(9)]);          // 10% gone
    expect(typesOf(pendingNow($this, $this->applicantUser)))->not->toContain('business.mid_docs');
    DB::table('milestones')->where('id', $id)->update(['exec_deadline_date' => now()->addDays(6)]);          // 40% gone
    expect(typesOf(pendingNow($this, $this->applicantUser)))->toContain('business.mid_docs');
    DB::table('milestones')->where('id', $id)->update(['mid_milestone_started' => 1]);
    expect(typesOf(pendingNow($this, $this->applicantUser)))->not->toContain('business.mid_docs');
    DB::table('milestones')->where('id', $id)->update(['mid_milestone_started' => 0, 'exec_deadline_date' => now()->subDay()]);
    expect(typesOf(pendingNow($this, $this->applicantUser)))->not->toContain('business.mid_docs'); // overdue is another case
});

it('passes a verification agreement between the applicant and the owner', function () {
    $this->application->update(['status' => 'approved', 'funding_setup_status' => 'awaiting_applicant_revision']);
    $ms = DB::table('program_milestones')->insertGetId(['app_id' => $this->application->id, 'sequence_order' => 1, 'title' => 'Seed', 'amount' => 100, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    $ag = DB::table('milestone_pre_agreements')->insertGetId(['milestone_id' => $ms, 'verification_type' => 'mprv', 'status' => 'pending', 'submitted_by' => $this->orgUser->id, 'created_at' => now(), 'updated_at' => now()]);

    expect(typesOf(pendingNow($this, $this->applicantUser)))->toContain('applicant.agreement')->and(typesOf(pendingNow($this, $this->orgUser)))->not->toContain('owner.agreement');
    DB::table('milestone_pre_agreements')->where('id', $ag)->update(['status' => 'submitted']);
    expect(typesOf(pendingNow($this, $this->applicantUser)))->not->toContain('applicant.agreement')->and(typesOf(pendingNow($this, $this->orgUser)))->toContain('owner.agreement');
    DB::table('milestone_pre_agreements')->where('id', $ag)->update(['status' => 'rejected']);
    expect(typesOf(pendingNow($this, $this->applicantUser)))->toContain('applicant.agreement');
    DB::table('milestone_pre_agreements')->where('id', $ag)->update(['status' => 'agreed']);
    expect(typesOf(pendingNow($this, $this->applicantUser)))->not->toContain('applicant.agreement')->and(typesOf(pendingNow($this, $this->orgUser)))->not->toContain('owner.agreement');
});

it('tells the owner about failed payments', function () {
    $this->application->update(['status' => 'approved']);
    $ms = DB::table('program_milestones')->insertGetId(['app_id' => $this->application->id, 'sequence_order' => 1, 'title' => 'Seed', 'amount' => 100, 'status' => 'disbursing', 'created_at' => now(), 'updated_at' => now()]);
    $d = DB::table('disbursements')->insertGetId(['milestone_id' => $ms, 'wallet_id' => $this->wallet->id, 'recipient_type' => 'supplier', 'amount' => 100, 'currency' => 'KES', 'payment_method' => 'mpesa_mobile', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    expect(typesOf(pendingNow($this, $this->orgUser)))->not->toContain('owner.disbursement_failed');
    DB::table('disbursements')->where('id', $d)->update(['status' => 'failed', 'failure_reason' => 'Wrong number']);
    $res = pendingNow($this, $this->orgUser);
    expect(typesOf($res))->toContain('owner.disbursement_failed');
    expect(typesOf(pendingNow($this, $this->applicantUser)))->not->toContain('owner.disbursement_failed');
});

it('tells the owner when the wallet cannot cover accepted reviewers', function () {
    $this->wallet->update(['balance' => 100]);
    $order = ['organization_id' => $this->organization->id, 'reviewer_id' => $this->reviewerUser->id, 'program_id' => $this->program->id, 'order_type' => 'round_review', 'round_id' => $this->round->id, 'fee_amount' => 400, 'work_status' => 'assigned', 'acceptance_status' => 'accepted', 'payment_status' => 'unpaid', 'created_at' => now(), 'updated_at' => now()];
    $id = DB::table('reviewer_orders')->insertGetId($order);
    $res = pendingNow($this, $this->orgUser);
    expect(typesOf($res))->toContain('owner.wallet_shortfall');
    $item = collect(collect($res->json('data.groups'))->firstWhere('key', 'manage')['items'])->firstWhere('type', 'owner.wallet_shortfall');
    expect($item['subtitle'])->toContain('300.00')->and($item['link'])->toContain('deposit=1');

    $this->wallet->update(['balance' => 1000]);
    expect(typesOf(pendingNow($this, $this->orgUser)))->not->toContain('owner.wallet_shortfall');
    $this->wallet->update(['balance' => 100]);
    DB::table('reviewer_orders')->where('id', $id)->update(['payment_status' => 'completed']);
    expect(typesOf(pendingNow($this, $this->orgUser)))->not->toContain('owner.wallet_shortfall');
});
