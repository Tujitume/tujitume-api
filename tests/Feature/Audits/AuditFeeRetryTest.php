<?php

use App\Models\Auth\User;
use App\Models\Finance\Transactions;
use App\Models\Programs\MilestoneVerification;
use App\Models\Programs\ProgramMilestone;
use App\Models\Shared\PMAudit;
use App\Service\Audits\AuditFee;
use App\Service\LiprMpesa\LiprW2W;

uses(Tests\Feature\Programs\ProgramTestCase::class);

/*
 * A project manager's audit fee whose payout failed (release_failed) can be asked for again by that project manager.
 * The failed state is claimed atomically, so a second click cannot pay twice; LIPR failing a payout later moves it back
 * and removes the transaction rows it had recorded.
 */

beforeEach(function () {
    $this->transfers = [];
    $this->liprOk = true;
    $test = $this;
    $this->app->instance(LiprW2W::class, new class($test) extends LiprW2W {
        public function __construct(private $test) {}
        public function send($amount, string $receiver_wallet, string $payer_wallet, $milestone = null, array $options = [])
        {
            $this->test->transfers[] = compact('amount', 'receiver_wallet', 'payer_wallet', 'options');
            return $this->test->liprOk
                ? ['success' => true, 'reference' => 'TR-'.count($this->test->transfers)]
                : ['success' => false, 'message' => 'Receiver wallet inactive'];
        }
    });

    $this->organization->forceFill(['lipr_wallet' => 'ORG-WALLET', 'wallet_balance' => 20000])->save();
    $this->pm = User::factory()->create(['user_type_id' => 3, 'lipr_wallet' => 'PM-WALLET']);
    $milestone = ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => 1, 'amount' => 5000, 'status' => 'submitted']);
    $this->verification = MilestoneVerification::create([
        'milestone_id' => $milestone->id, 'verification_type' => 'mprv', 'decision' => 'audit_requested', 'submitted_by' => $this->applicantUser->id,
        'auditor_id' => $this->pm->id, 'audit_status' => 'reported', 'audit_fee' => 3000, 'audit_fee_status' => 'release_failed',
        'audit_fee_paid_by' => $this->orgUser->id, 'audit_started_at' => now(),
    ]);
    $this->url = "/api/v1/pm-audits/program/{$this->verification->id}/retry-fee";
});

it('shows a failed payout as retryable and pays it once on retry', function () {
    $row = $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/pm-audits/pre_release')->assertOk()->json('data.0');
    expect($row['fee_status'])->toBe('release_failed')->and($row['can_retry_fee'])->toBeTrue();

    $this->actingAs($this->pm, 'sanctum')->postJson($this->url)->assertOk()->assertJsonPath('data.fee_status', 'released');
    $v = $this->verification->fresh();
    expect($v->audit_fee_status)->toBe('released')->and($v->audit_fee_reference)->toBe('TR-1')
        ->and($this->transfers)->toHaveCount(1)
        ->and($this->transfers[0]['receiver_wallet'])->toBe('PM-WALLET')
        ->and(Transactions::where('type', 'pm_audit_fee')->count())->toBe(2);

    // a second click does nothing
    $this->actingAs($this->pm, 'sanctum')->postJson($this->url)->assertStatus(409);
    expect($this->transfers)->toHaveCount(1);
});

it('keeps it retryable when the payout fails again', function () {
    $this->liprOk = false;
    $this->actingAs($this->pm, 'sanctum')->postJson($this->url)->assertStatus(502)->assertJsonPath('data.fee_status', 'release_failed');
    expect($this->verification->fresh()->audit_fee_status)->toBe('release_failed')->and(Transactions::count())->toBe(0);
});

it('lets only the project manager retry, and only once they have a wallet', function () {
    $this->actingAs($this->orgUser, 'sanctum')->postJson($this->url)->assertStatus(403);
    $other = User::factory()->create(['user_type_id' => 3, 'lipr_wallet' => 'X']);
    $this->actingAs($other, 'sanctum')->postJson($this->url)->assertStatus(403);

    $this->pm->forceFill(['lipr_wallet' => null])->save();
    $this->actingAs($this->pm->fresh(), 'sanctum')->postJson($this->url)->assertStatus(422);
    expect($this->transfers)->toBe([])->and($this->verification->fresh()->audit_fee_status)->toBe('release_failed');

    $this->verification->update(['audit_fee_status' => 'held']);
    $this->pm->forceFill(['lipr_wallet' => 'PM-WALLET'])->save();
    $this->actingAs($this->pm->fresh(), 'sanctum')->postJson($this->url)->assertStatus(409);
});

it('moves a payout LIPR later failed back to retryable and drops its records', function () {
    $this->actingAs($this->pm, 'sanctum')->postJson($this->url)->assertOk();
    expect(Transactions::where('reference_id', 'TR-1')->count())->toBe(2);

    config(['services.lipr.verify_callbacks' => false]);
    $this->postJson('/api/lipr-callback/audit-fee', [
        'transaction' => ['reference' => 'TR-1', 'transactionStatus' => 'failed'], 'metadata' => ['listingId' => 'mv-'.$this->verification->id],
    ])->assertOk();
    expect($this->verification->fresh()->audit_fee_status)->toBe('release_failed')
        ->and(Transactions::where('reference_id', 'TR-1')->count())->toBe(0);

    // and the retry works again
    $this->actingAs($this->pm, 'sanctum')->postJson($this->url)->assertOk();
    expect($this->verification->fresh()->audit_fee_status)->toBe('released');
});

it('takes a failed business fee off the owner release and settles it as deducted on retry', function () {
    $audit = PMAudit::create(['milestone_id' => 999, 'assigned_pm_id' => $this->pm->id, 'type' => 'pre_release', 'status' => 'accepted', 'fee' => 1300, 'fee_status' => 'release_failed']);

    // still owed to the PM: the KSh fee comes off the owner's KSh release and waits as deducted_unpaid
    expect(AuditFee::businessDeductionUsd(999))->toBe(1300.0);
    AuditFee::markBusinessDeducted(999);
    expect($audit->fresh()->fee_status)->toBe(AuditFee::DEDUCTED_UNPAID)
        ->and(AuditFee::businessDeductionUsd(999))->toBe(0.0);

    // a failed retry keeps it deducted_unpaid (never deducted twice); a paid one settles it
    \App\Models\Misc\Setting::updateOrCreate(['key' => 'platform_lipr_wallet'], ['value' => 'TUJ-WALLET']);
    $this->liprOk = false;
    $this->actingAs($this->pm, 'sanctum')->postJson("/api/v1/pm-audits/business/{$audit->id}/retry-fee")->assertStatus(502);
    expect($audit->fresh()->fee_status)->toBe(AuditFee::DEDUCTED_UNPAID)->and(AuditFee::businessDeductionUsd(999))->toBe(0.0);

    $this->liprOk = true;
    $this->actingAs($this->pm, 'sanctum')->postJson("/api/v1/pm-audits/business/{$audit->id}/retry-fee")->assertOk()->assertJsonPath('data.fee_status', 'deducted');
    expect($audit->fresh()->fee_status)->toBe('deducted')->and(AuditFee::businessDeductionUsd(999))->toBe(0.0)
        ->and(end($this->transfers)['payer_wallet'])->toBe('TUJ-WALLET');
});
