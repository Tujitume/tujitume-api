<?php

use App\Models\Auth\User;
use App\Models\Finance\Transactions;
use App\Models\Misc\Setting;
use App\Service\LiprMpesa\LiprW2W;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(Tests\Feature\Capital\CapitalTestCase::class);

/*
 * DECISION-9 for capital: the capital provider may ask a project manager to audit a milestone before releasing it. The PM
 * accepts with a fee (or declines); the provider pays it into escrow; the PM reports and is paid; a cancel refunds it.
 * While the audit is open the milestone cannot be released (409); the provider always decides.
 */

beforeEach(function () {
    [$this->pitch, $this->first, $this->second] = $this->fundablePitch();
    $this->transfers = [];
    $test = $this;
    $this->app->instance(LiprW2W::class, new class($test) extends LiprW2W {
        public function __construct(private $test) {}
        public function send($amount, string $receiver_wallet, string $payer_wallet, $milestone = null, array $options = [])
        {
            $this->test->transfers[] = compact('amount', 'receiver_wallet', 'payer_wallet', 'options');
            return ['success' => true, 'reference' => 'TR-'.count($this->test->transfers)];
        }
    });
    Setting::updateOrCreate(['key' => 'platform_lipr_wallet'], ['value' => 'PLATFORM-WALLET']);
    $this->capitalOwner->forceFill(['lipr_wallet' => 'CAP-WALLET'])->save();
    $this->pm = User::factory()->create(['user_type_id' => 3, 'first_name' => 'Pat', 'lipr_wallet' => 'PM-WALLET']);
    $this->asPm = fn () => $this->actingAs($this->pm, 'sanctum');
    $this->url = fn ($m, string $tail = '') => "/api/v1/capital/milestones/{$m->id}/audit{$tail}";
    $this->pmUrl = fn ($m, string $action) => "/api/v1/pm-audits/capital/{$m->id}/{$action}";
    $this->ask = fn ($milestone = null) => $this->asCapital()->postJson(($this->url)($milestone ?? $this->first), ['auditor_id' => $this->pm->id, 'notes' => 'Check the pilot sites.'])->assertOk();
    $this->release = fn () => $this->asCapital()->postJson('/api/v1/capital/capital-milestone', ['listing' => $this->first->id, 'stripeToken' => 'tok']);
});


it('runs a paid audit from request to report, paying the fee to the PM, and blocks the release meanwhile', function () {
    capitalFakeStripeForAudit($this); // before the first release call: the route keeps its controller (and Stripe client)
    ($this->ask)();
    $m = $this->first->fresh();
    expect($m->audit_status)->toBe('pending')->and((int) $m->auditor_id)->toBe($this->pm->id)->and((int) $m->audit_requested_by)->toBe($this->capitalOwner->id);

    // the PM, the business owner and nobody else were told
    expect(DB::table('notifications')->where('receiver_id', $this->pm->id)->value('link'))->toBe('dashboard.serviceProvider.pmAudits.finalApproval?source=capital')
        ->and(DB::table('notifications')->where('receiver_id', $this->applicantUser->id)->latest('id')->value('text'))->toContain('will check Pilot');

    // release is blocked with a plain message
    ($this->release)()->assertStatus(409)->assertJsonPath('message', 'A project manager is auditing this milestone. Wait for their report, or cancel the audit, then release the funds.');

    // the PM sees it in Project Manager Audits (final approval, capital) and in Pending
    $row = collect(($this->asPm)()->getJson('/api/v1/pm-audits/final_approval?source=capital')->assertOk()->json('data'))->first();
    expect($row)->toMatchArray(['key' => 'capital:'.$m->id, 'source' => 'capital', 'status' => 'pending', 'business_name' => 'Maji Startup', 'can_respond' => true, 'decides' => 'provider']);
    expect(($this->asPm)()->getJson('/api/v1/pm-audits/summary')->json('data.final_approval.by_source.capital'))->toBe(1);
    \Illuminate\Support\Facades\Cache::flush();
    $types = collect(($this->asPm)()->getJson('/api/v1/pending-tasks?fresh=1')->json('data.groups'))->flatMap(fn ($g) => collect($g['items'])->pluck('type'));
    expect($types)->toContain('audit.capital');

    // accept with a fee: no report before it is paid
    ($this->asPm)()->postJson(($this->pmUrl)($m, 'respond'), ['action' => 'accept', 'fee' => 1500])->assertOk();
    expect($m->fresh()->audit_status)->toBe('quoted');
    ($this->asPm)()->postJson(($this->pmUrl)($m, 'report'), ['recommendation' => 'approve', 'notes' => 'The pilot sites are built.'])->assertStatus(409);

    // the provider pays into escrow (their LIPR wallet → Tujitume's)
    $this->asCapital()->postJson(($this->url)($m, '/pay'))->assertOk();
    expect($m->fresh()->audit_status)->toBe('accepted')->and($m->fresh()->audit_fee_status)->toBe('held')
        ->and($this->transfers[0])->toMatchArray(['amount' => 1500, 'receiver_wallet' => 'PLATFORM-WALLET', 'payer_wallet' => 'CAP-WALLET']);
    $this->asCapital()->postJson(($this->url)($m, '/pay'))->assertStatus(409);
    ($this->release)()->assertStatus(409);

    // the report, with evidence: the fee goes to the PM
    Storage::fake('s3');
    ($this->asPm)()->post(($this->pmUrl)($m, 'report'), [
        'recommendation' => 'approve', 'notes' => 'The pilot sites are built and working.',
        'evidence' => [UploadedFile::fake()->create('site.pdf', 20, 'application/pdf')],
    ], ['Accept' => 'application/json'])->assertOk();
    $m->refresh();
    expect($m->audit_status)->toBe('reported')->and($m->audit_recommendation)->toBe('approve')->and($m->audit_fee_status)->toBe('released')
        ->and($this->transfers[1])->toMatchArray(['amount' => 1500, 'receiver_wallet' => 'PM-WALLET', 'payer_wallet' => 'PLATFORM-WALLET'])
        ->and(json_decode($m->audit_evidence, true)[0]['name'])->toBe('site.pdf');
    expect(Transactions::where('type', 'pm_audit_fee')->where('user_id', $this->capitalOwner->id)->value('direction'))->toBe('debit')
        ->and(Transactions::where('type', 'pm_audit_fee')->where('user_id', $this->pm->id)->value('direction'))->toBe('credit');

    // both deal pages show it: the provider with the fee, the business owner the outcome only
    $provider = collect($this->asCapital()->getJson("/api/v1/capital/pitch/{$this->pitch->id}")->assertOk()->json('pitch.capital_milestones'))->firstWhere('id', $m->id);
    expect($provider['audit'])->toMatchArray(['status' => 'reported', 'recommendation' => 'approve', 'fee' => 1500, 'fee_status' => 'released', 'can_cancel' => false])
        ->and($provider['audit']['auditor']['name'])->toContain('Pat')->and($provider['audit']['evidence'])->toHaveCount(1)
        ->and($provider['can_request_audit'])->toBeFalse()->and($provider)->not->toHaveKey('audit_fee');
    $applicant = collect($this->actingAsApplicant()->getJson("/api/v1/capital/pitch/{$this->pitch->id}")->assertOk()->json('pitch.capital_milestones'))->firstWhere('id', $m->id);
    expect($applicant['audit'])->toHaveKeys(['status', 'recommendation', 'notes'])->not->toHaveKey('fee')
        ->and($applicant)->not->toHaveKey('can_request_audit');

    // the report never releases by itself: the provider decides and can release now
    expect($m->fund_released)->toBeFalse();
    ($this->release)()->assertOk();
});

it('refunds the held fee when the provider cancels, and lets them release themselves', function () {
    ($this->ask)();
    ($this->asPm)()->postJson(($this->pmUrl)($this->first, 'respond'), ['action' => 'accept', 'fee' => 800])->assertOk();
    $this->asCapital()->postJson(($this->url)($this->first, '/pay'))->assertOk();

    $this->asCapital()->postJson(($this->url)($this->first, '/cancel'), ['reason' => 'We visited ourselves.'])->assertOk();
    $m = $this->first->fresh();
    expect($m->audit_status)->toBe('cancelled')->and($m->audit_fee_status)->toBe('refunded')->and($m->audit_cancel_reason)->toBe('We visited ourselves.')
        ->and($this->transfers[1])->toMatchArray(['amount' => 800, 'receiver_wallet' => 'CAP-WALLET', 'payer_wallet' => 'PLATFORM-WALLET']);
    expect(DB::table('notifications')->where('receiver_id', $this->pm->id)->latest('id')->value('text'))->toContain('cancelled');

    // the PM can no longer report; the provider releases
    ($this->asPm)()->postJson(($this->pmUrl)($m, 'report'), ['recommendation' => 'approve', 'notes' => 'The pilot sites are built.'])->assertStatus(409);
    $this->asCapital()->postJson(($this->url)($m, '/cancel'))->assertStatus(409);
    capitalFakeStripeForAudit($this);
    ($this->release)()->assertOk();
});

it('frees the release when the PM declines, and a free audit starts at once', function () {
    ($this->ask)();
    ($this->asPm)()->postJson(($this->pmUrl)($this->first, 'respond'), ['action' => 'decline'])->assertOk();
    expect($this->first->fresh()->audit_status)->toBe('declined');
    ($this->asPm)()->postJson(($this->pmUrl)($this->first, 'respond'), ['action' => 'accept'])->assertStatus(409);

    // ask again (another try), accepted for free: under way without any payment
    ($this->ask)();
    ($this->asPm)()->postJson(($this->pmUrl)($this->first, 'respond'), ['action' => 'accept'])->assertOk();
    expect($this->first->fresh()->audit_status)->toBe('accepted');
    ($this->asPm)()->postJson(($this->pmUrl)($this->first, 'report'), ['recommendation' => 'reject', 'notes' => 'Two sites are not finished.'])->assertOk();
    expect($this->transfers)->toBe([])->and($this->first->fresh()->audit_fee_status)->toBeNull();
    // after a report it cannot be asked again; the provider decides
    $this->asCapital()->postJson(($this->url)($this->first), ['auditor_id' => $this->pm->id])->assertStatus(409);
});

it('only lets the owner or a super admin of the deal run the audit, and only the assigned PM answer it', function () {
    $editor = $this->teamMember($this->capitalOwner, 'editor');
    $this->asCapital($editor)->postJson(($this->url)($this->first), ['auditor_id' => $this->pm->id])->assertStatus(403);
    $this->asCapital($this->makeCapitalOwner())->postJson(($this->url)($this->first), ['auditor_id' => $this->pm->id])->assertStatus(404);
    $this->actingAsApplicant()->postJson(($this->url)($this->first), ['auditor_id' => $this->pm->id])->assertStatus(403);
    // only a service provider can audit
    $this->asCapital()->postJson(($this->url)($this->first), ['auditor_id' => $this->applicantUser->id])->assertStatus(422);

    $admin = $this->teamMember($this->capitalOwner, 'super_admin');
    $this->asCapital($admin)->postJson(($this->url)($this->first), ['auditor_id' => $this->pm->id])->assertOk();
    $this->asCapital()->postJson(($this->url)($this->first), ['auditor_id' => $this->pm->id])->assertStatus(409);

    $other = User::factory()->create(['user_type_id' => 3]);
    $this->actingAs($other, 'sanctum')->postJson(($this->pmUrl)($this->first, 'respond'), ['action' => 'accept'])->assertStatus(403);

    // a released milestone cannot be audited
    $this->second->update(['fund_released' => true, 'release_status' => 'released', 'status' => 1]);
    $this->asCapital()->postJson(($this->url)($this->second), ['auditor_id' => $this->pm->id])->assertStatus(409);
});

it('blocks "release all" while any remaining milestone is being audited', function () {
    ($this->ask)($this->second);
    $this->asCapital()->postJson('/api/v1/capital/capital-milestone', ['listing' => $this->first->id, 'stripeToken' => 'tok', 'percent' => 100])->assertStatus(409);
});

function capitalFakeStripeForAudit($test): void
{
    $test->first->fresh()->update(['release_status' => null]);
    $test->applicantUser->forceFill(['stripe_connect_id' => 'acct_sme'])->save();
    app()->instance(\Stripe\StripeClient::class, new class extends \Stripe\StripeClient {
        public function __construct() { parent::__construct('sk_test_fake'); }
        public function __get($name)
        {
            return new class($name) {
                public function __construct(private string $name) {}
                public function create(array $params, $opts = null)
                {
                    return (object) ['id' => ($this->name === 'charges' ? 'ch_' : 'tr_').'1', 'status' => 'succeeded'];
                }
            };
        }
    });
}
