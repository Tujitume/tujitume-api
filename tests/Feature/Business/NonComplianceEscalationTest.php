<?php

use App\Console\Commands\MilestoneNotifyActions;
use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Communication\Notifications;
use App\Models\Milestones\Milestones;
use App\Models\Milestones\NonCompliance\MilestoneNonCompliance;
use App\Models\Shared\Vote;
use App\Service\Business\Milestone\MilestoneLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class, RefreshDatabase::class);

// A business milestone past its deadline under 60%: the owner has 72 hours, then the investors take control (AMAP)

beforeEach(function () {
    Carbon::setTestNow('2026-11-20 10:00:00');
    $this->owner = User::factory()->create(['user_type_id' => 1]);
    $this->big = User::factory()->create(['user_type_id' => 2]);
    $this->small = User::factory()->create(['user_type_id' => 2]);
    $this->stranger = User::factory()->create(['user_type_id' => 2]);
    $this->listing = Listing::create(['user_id' => $this->owner->id, 'name' => 'Maji Kiosk', 'category' => 'Water', 'stage' => 'seed',
        'location' => 'Nairobi', 'investment_needed' => 1000, 'amount_collected' => 1000, 'active' => 1, 'threshold_met' => 1]);
    $this->m = Milestones::create(['user_id' => $this->owner->id, 'listing_id' => $this->listing->id, 'title' => 'Build kiosk', 'amount' => 5000,
        'status' => 'to_do', 'progress_percentage' => 30, 'funding_collected' => 1000, 'deadline_date' => now()->subDays(2)]);
    foreach ([[$this->big, 700], [$this->small, 300]] as $i => [$investor, $amount]) {
        DB::table('accepted_bids')->insert(['bid_id' => $i + 1, 'ms_id' => $this->m->id, 'investor_id' => $investor->id, 'business_id' => $this->listing->id,
            'owner_id' => $this->owner->id, 'type' => 'Monetary', 'method' => 'lipr', 'amount' => $amount, 'status' => 'confirmed',
            'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->sweep = fn () => app(MilestoneNotifyActions::class)->resolveNonCompliances();
    $this->flag = function () {
        $lifecycle = new MilestoneLifecycleService();
        $lifecycle->evaluateListing($this->listing->fresh());
        $lifecycle->evaluateListing($this->listing->fresh()); // a second run changes nothing

        return MilestoneNonCompliance::where('milestone_id', $this->m->id)->first();
    };
    $this->notices = fn (User $u, string $like) => Notifications::where('receiver_id', $u->id)->where('text', 'like', "%{$like}%")->get();
});

afterEach(fn () => Carbon::setTestNow());

it('opens the 72-hour window once and tells the owner and every investor where to act', function () {
    $nc = ($this->flag)();

    expect($this->m->fresh()->status)->toBe('non_compliant')
        ->and($nc->stage)->toBe('response_window')
        ->and($nc->response_deadline->toDateTimeString())->toBe('2026-11-23 10:00:00');

    $own = ($this->notices)($this->owner, 'non-compliant');
    expect($own)->toHaveCount(1)->and($own[0]->text)->toContain('72 hours')->toContain('% progress')
        ->and($own[0]->link)->toBe("dashboard.entrepreneur.dealroom::{$this->listing->id}?tab=non-compliance");
    foreach ([$this->big, $this->small] as $investor) {
        $n = ($this->notices)($investor, 'non-compliant');
        expect($n)->toHaveCount(1)->and($n[0]->link)->toContain('dashboard.investor.dealroom?business=@')->toContain('tab=non-compliance');
    }
    expect(($this->notices)($this->stranger, 'non-compliant'))->toHaveCount(0);

    $subjects = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($x) => (string) $x->getOriginalMessage()->getSubject());
    expect($subjects->filter(fn ($s) => str_contains($s, '72 hours to respond')))->toHaveCount(3);
});

it('hands the case to the investors when the 72 hours pass, once, and the vote decides', function () {
    $nc = ($this->flag)();

    Carbon::setTestNow('2026-11-23 09:00:00');
    ($this->sweep)();
    expect($nc->fresh()->stage)->toBe('response_window'); // not yet

    Carbon::setTestNow('2026-11-23 10:30:00');
    ($this->sweep)();
    ($this->sweep)(); // the next hour changes nothing
    expect($nc->fresh()->stage)->toBe('ipm')
        ->and(Vote::where('type', 'non_compliance')->where('reference_id', $nc->id)->where('status', 'open')->count())->toBe(1)
        ->and(($this->notices)($this->big, 'Investors now decide'))->toHaveCount(1)
        ->and(($this->notices)($this->owner, 'did not respond'))->toHaveCount(1);

    // Strangers and the owner cannot vote; a freeze needs a reason
    $this->actingAs($this->stranger)->postJson('/api/v1/milestones/non-compliance/votes', ['non_compliance_id' => $nc->id, 'vote' => 'continue'])->assertForbidden();
    $this->actingAs($this->owner)->postJson('/api/v1/milestones/non-compliance/votes', ['non_compliance_id' => $nc->id, 'vote' => 'continue'])->assertForbidden();
    $this->actingAs($this->stranger)->getJson("/api/v1/milestones/{$this->m->id}/non-compliances")->assertForbidden();
    $this->actingAs($this->big)->postJson('/api/v1/milestones/non-compliance/votes', ['non_compliance_id' => $nc->id, 'vote' => 'freeze'])->assertUnprocessable();

    // The small investor alone (30%) does not decide; the big one (70%) reaches quorum: continue wins by weight
    $this->actingAs($this->small)->postJson('/api/v1/milestones/non-compliance/votes', ['non_compliance_id' => $nc->id, 'vote' => 'dispute', 'reason' => 'No progress'])->assertOk();
    expect($nc->fresh()->stage)->toBe('ipm');
    $this->actingAs($this->big)->postJson('/api/v1/milestones/non-compliance/votes', ['non_compliance_id' => $nc->id, 'vote' => 'continue'])
        ->assertOk()->assertJsonPath('stage', 'resolved');

    $m = $this->m->fresh();
    expect($nc->fresh()->resolution_result)->toBe('continued')
        ->and($m->status)->toBe('to_do')
        ->and(Carbon::parse($m->deadline_date)->toDateString())->toBe('2026-11-30')
        ->and(Vote::where('reference_id', $nc->id)->where('status', 'open')->count())->toBe(0)
        ->and(($this->notices)($this->owner, 'voted to continue'))->toHaveCount(1);

    // Voting again after the result is refused
    $this->actingAs($this->small)->postJson('/api/v1/milestones/non-compliance/votes', ['non_compliance_id' => $nc->id, 'vote' => 'continue'])->assertUnprocessable();
});

it('lets the owner answer within the window, which hands it to the investors straight away', function () {
    Storage::fake('s3');
    $nc = ($this->flag)();
    $file = fn () => UploadedFile::fake()->create('plan.pdf', 50, 'application/pdf');

    $this->actingAs($this->big)->getJson("/api/v1/milestones/{$this->m->id}/non-compliances")->assertOk()->assertJsonPath('data.can_respond', false);
    $this->actingAs($this->owner)->getJson("/api/v1/milestones/{$this->m->id}/non-compliances")->assertOk()->assertJsonPath('data.can_respond', true);

    $this->actingAs($this->big)->post('/api/v1/milestones/non-compliance/response-upload', [
        'non_compliance_id' => $nc->id, 'response_type' => 'rmep', 'documents' => [$file()]], ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs($this->big)->postJson('/api/v1/milestones/non-compliance/votes', ['non_compliance_id' => $nc->id, 'vote' => 'continue'])->assertUnprocessable(); // not open yet

    $this->actingAs($this->owner)->post('/api/v1/milestones/non-compliance/response-upload', [
        'non_compliance_id' => $nc->id, 'response_type' => 'rmep', 'note' => 'New supplier booked.', 'documents' => [$file()]], ['Accept' => 'application/json'])->assertOk();

    $nc->refresh();
    expect($nc->stage)->toBe('ipm')->and($nc->owner_response_type)->toBe('rmep')->and($nc->owner_response_note)->toBe('New supplier booked.')
        ->and($nc->documents()->count())->toBe(1)
        ->and(($this->notices)($this->small, 'the owner sent a revised plan'))->toHaveCount(1);

    // A second answer is refused, and the hourly sweep does not hand it over again
    $this->actingAs($this->owner)->post('/api/v1/milestones/non-compliance/response-upload', [
        'non_compliance_id' => $nc->id, 'response_type' => 'rmep', 'documents' => [$file()]], ['Accept' => 'application/json'])->assertUnprocessable();
    Carbon::setTestNow('2026-11-24 10:00:00');
    ($this->sweep)();
    expect(($this->notices)($this->small, 'Investors now decide'))->toHaveCount(1);
});

it('freezes and opens a platform dispute when nobody votes in 7 days, then sanctions after 14 unresolved days', function () {
    $nc = ($this->flag)();
    Carbon::setTestNow('2026-11-23 11:00:00');
    ($this->sweep)();

    Carbon::setTestNow('2026-11-30 12:00:00');
    ($this->sweep)();
    expect($nc->fresh()->stage)->toBe('pid')->and($this->m->fresh()->status)->toBe('locked')
        ->and(($this->notices)($this->owner, 'No investor voted'))->toHaveCount(1);

    // The lifecycle does not unlock a frozen milestone
    (new MilestoneLifecycleService())->evaluateListing($this->listing->fresh());
    expect($this->m->fresh()->status)->toBe('locked');

    Carbon::setTestNow('2026-12-14 13:00:00');
    ($this->sweep)();
    ($this->sweep)();
    expect($nc->fresh()->stage)->toBe('sanctioned')
        ->and(DB::table('business_sanctions')->where('business_id', $this->listing->id)->count())->toBe(1)
        ->and(($this->notices)($this->big, 'sanctioned'))->toHaveCount(1);
});

it('lets Tujitume settle a platform dispute: continue unlocks it with 7 more days, once', function () {
    $nc = ($this->flag)();
    Carbon::setTestNow('2026-11-23 11:00:00');
    ($this->sweep)();
    Carbon::setTestNow('2026-11-30 12:00:00');
    ($this->sweep)();
    expect($nc->fresh()->stage)->toBe('pid');

    $admin = \App\Models\Admin::create(['name' => 'Ops', 'email' => 'ops@tujitume.test', 'password' => bcrypt('secret')]);
    $this->get(route('admin.non_compliance.index'))->assertRedirect(); // signed-out
    $this->actingAs($this->owner)->post(route('admin.non_compliance.resolve', $nc->id), ['outcome' => 'continue'])->assertRedirect();
    expect($nc->fresh()->stage)->toBe('pid'); // a normal user cannot settle it

    $this->actingAs($admin, 'admin')->get(route('admin.non_compliance.index'))->assertOk()->assertSee('Maji Kiosk')->assertSee('Let it continue');
    $this->actingAs($admin, 'admin')->post(route('admin.non_compliance.resolve', $nc->id), ['outcome' => 'continue'])
        ->assertRedirect()->assertSessionHas('success');
    $m = $this->m->fresh();
    expect($nc->fresh()->stage)->toBe('resolved')->and($nc->fresh()->resolution_result)->toBe('continued')
        ->and($m->status)->toBe('to_do')->and((bool) $m->active)->toBeTrue()
        ->and(Carbon::parse($m->deadline_date)->toDateString())->toBe('2026-12-07')
        ->and(($this->notices)($this->owner, 'Tujitume reviewed the dispute'))->toHaveCount(1)
        ->and(($this->notices)($this->small, 'Tujitume reviewed the dispute'))->toHaveCount(1);

    // a second click, or the 14-day sweep, changes nothing
    $this->actingAs($admin, 'admin')->post(route('admin.non_compliance.resolve', $nc->id), ['outcome' => 'sanction'])->assertSessionHas('error');
    Carbon::setTestNow('2026-12-15 13:00:00');
    ($this->sweep)();
    expect($nc->fresh()->stage)->toBe('resolved')->and(DB::table('business_sanctions')->count())->toBe(0);
});

it('lets Tujitume sanction a disputed business before the 14 days run out', function () {
    $nc = ($this->flag)();
    Carbon::setTestNow('2026-11-23 11:00:00');
    ($this->sweep)();
    Carbon::setTestNow('2026-11-30 12:00:00');
    ($this->sweep)();

    $admin = \App\Models\Admin::create(['name' => 'Ops', 'email' => 'ops@tujitume.test', 'password' => bcrypt('secret')]);
    $this->actingAs($admin, 'admin')->post(route('admin.non_compliance.resolve', $nc->id), ['outcome' => 'sanction'])->assertSessionHas('success');
    expect($nc->fresh()->stage)->toBe('sanctioned')
        ->and(DB::table('business_sanctions')->where('business_id', $this->listing->id)->count())->toBe(1)
        ->and($this->m->fresh()->status)->toBe('locked')
        ->and(($this->notices)($this->big, 'Tujitume reviewed the dispute'))->toHaveCount(1)
        ->and(($this->notices)($this->big, 'not resolved within'))->toHaveCount(0);
});

it('opens the owner\'s answer files for the owner and this milestone\'s investors only', function () {
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(fn ($path) => 'https://files.test/'.$path);
    $nc = ($this->flag)();
    $this->actingAs($this->owner)->post('/api/v1/milestones/non-compliance/response-upload', [
        'non_compliance_id' => $nc->id, 'response_type' => 'completion_proof', 'documents' => [UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf')]],
        ['Accept' => 'application/json'])->assertOk();
    $doc = DB::table('nc_documents')->where('nc_id', $nc->id)->first();
    $url = "/api/v1/deal-room/milestone/{$this->m->id}/file?kind=nc&id={$doc->id}";

    // the case lists it with the id the screens open it by
    $this->actingAs($this->big)->getJson("/api/v1/milestones/{$this->m->id}/non-compliances")->assertOk()->assertJsonPath('data.documents.0.id', $doc->id);
    foreach ([$this->owner, $this->big, $this->small] as $user) {
        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('url', 'https://files.test/'.$doc->file_path);
    }

    // someone with no part in this milestone (a bidder on the business only, or a stranger) gets nothing
    DB::table('business_bids')->insert(['investor_id' => $this->stranger->id, 'business_id' => $this->listing->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->stranger)->getJson($url)->assertNotFound();
    $this->actingAs(User::factory()->create(['user_type_id' => 2]))->getJson($url)->assertNotFound();
    // a document id from another milestone's case is not served through this one
    $this->actingAs($this->owner)->getJson("/api/v1/deal-room/milestone/{$this->m->id}/file?kind=nc&id=999")->assertNotFound();
});
