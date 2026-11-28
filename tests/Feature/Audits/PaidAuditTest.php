<?php

use App\Models\Auth\User;
use App\Models\Finance\Transactions;
use App\Models\Organizations\OrganizationAuditLog;
use App\Models\Programs\MilestonePreAgreement;
use App\Models\Programs\MilestoneVerification;
use App\Models\Programs\ProgramMilestone;
use App\Service\LiprMpesa\LiprW2W;

uses(Tests\Feature\Programs\ProgramTestCase::class);

/*
 * DECISION-9: project manager audits are paid (quoted on accepting, paid into escrow by the requester, released on the
 * report, refunded on cancel). DECISION-8 change: the owner team may skip a suggested audit, cancel an open one, and
 * reopen an agreement rejected twice.
 */

beforeEach(function () {
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

    $this->organization->forceFill(['lipr_wallet' => 'ORG-WALLET', 'wallet_balance' => 20000])->save();
    $this->pm = User::factory()->create(['user_type_id' => 3, 'first_name' => 'Pat', 'lipr_wallet' => 'PM-WALLET']);
    $this->milestone = ProgramMilestone::factory()->create([
        'app_id' => $this->application->id, 'sequence_order' => 1, 'title' => 'Buy equipment', 'amount' => 5000, 'status' => 'submitted',
    ]);
    $this->verification = MilestoneVerification::create([
        'milestone_id' => $this->milestone->id, 'verification_type' => 'mprv', 'decision' => 'pending', 'submitted_by' => $this->applicantUser->id,
    ]);
    $this->owner = fn () => $this->actingAs($this->orgUser, 'sanctum');
    $this->asPm = fn () => $this->actingAs($this->pm, 'sanctum');
    $this->v = fn (string $action) => "/api/v1/programs/verifications/{$this->verification->id}/{$action}";
});

function paRequest($test): void
{
    ($test->owner)()->patchJson(($test->v)('request-audit'), ['auditor_id' => $test->pm->id])->assertOk();
}

it('charges the quoted fee into escrow and pays it to the project manager with the report', function () {
    paRequest($this);
    ($this->asPm)()->postJson("/api/v1/pm-audits/program/{$this->verification->id}/respond", ['action' => 'accept', 'fee' => 3000])->assertOk();
    $v = $this->verification->fresh();
    expect($v->audit_status)->toBe('quoted')->and((float) $v->audit_fee)->toBe(3000.0);

    // no work before the fee is paid
    ($this->asPm)()->postJson("/api/v1/pm-audits/program/{$v->id}/report", ['recommendation' => 'approve', 'notes' => 'Receipts match the budget.'])
        ->assertStatus(409)->assertJsonFragment(['message' => 'The audit fee has not been paid yet. You can start once the program team pays it.']);

    ($this->owner)()->patchJson(($this->v)('pay-audit'))->assertOk();
    $v->refresh();
    expect($v->audit_status)->toBe('accepted')->and($v->audit_fee_status)->toBe('held')
        ->and((float) $this->organization->fresh()->wallet_balance)->toBe(17000.0)
        ->and($this->transfers)->toBe([]);

    ($this->asPm)()->postJson("/api/v1/pm-audits/program/{$v->id}/report", ['recommendation' => 'approve', 'notes' => 'Receipts match the budget.'])->assertOk();
    $v->refresh();
    expect($v->audit_status)->toBe('reported')->and($v->audit_fee_status)->toBe('released')->and($v->audit_fee_reference)->toBe('TR-1')
        ->and($this->transfers[0]['amount'])->toBe(3000)
        ->and($this->transfers[0]['receiver_wallet'])->toBe('PM-WALLET')
        ->and($this->transfers[0]['payer_wallet'])->toBe('ORG-WALLET')
        ->and((float) $this->organization->fresh()->wallet_balance)->toBe(17000.0);

    // both sides see it
    expect(Transactions::where('type', 'pm_audit_fee')->where('user_id', $this->orgUser->id)->value('direction'))->toBe('debit')
        ->and(Transactions::where('type', 'pm_audit_fee')->where('user_id', $this->pm->id)->value('direction'))->toBe('credit');

    // the owner decides as before
    ($this->owner)()->patchJson(($this->v)('approve'))->assertOk();
});

it('starts a free audit at once and refuses a fee the organization wallet cannot cover', function () {
    paRequest($this);
    ($this->asPm)()->postJson("/api/v1/pm-audits/program/{$this->verification->id}/respond", ['action' => 'accept', 'fee' => 50000])->assertOk();
    $res = ($this->owner)()->patchJson(($this->v)('pay-audit'))->assertStatus(422);
    expect($res->json('message'))->toContain('organization wallet has KES 20,000.00')
        ->and($this->verification->fresh()->audit_status)->toBe('quoted')
        ->and((float) $this->organization->fresh()->wallet_balance)->toBe(20000.0);

    // free audit: accepted straight away, nothing to pay or release
    $this->verification->fresh()->update(['audit_status' => 'pending', 'audit_fee' => null, 'audit_fee_status' => null]);
    ($this->asPm)()->postJson("/api/v1/pm-audits/program/{$this->verification->id}/respond", ['action' => 'accept'])->assertOk();
    expect($this->verification->fresh()->audit_status)->toBe('accepted');
    ($this->asPm)()->postJson("/api/v1/pm-audits/program/{$this->verification->id}/report", ['recommendation' => 'reject', 'notes' => 'Two receipts are missing.'])->assertOk();
    expect($this->transfers)->toBe([])->and($this->verification->fresh()->audit_fee_status)->toBeNull();
});

it('lets the owner team cancel an open audit, refunding the held fee', function () {
    paRequest($this);
    ($this->asPm)()->postJson("/api/v1/pm-audits/program/{$this->verification->id}/respond", ['action' => 'accept', 'fee' => 3000])->assertOk();
    ($this->owner)()->patchJson(($this->v)('pay-audit'))->assertOk();

    ($this->actingAs($this->applicantUser, 'sanctum'))->patchJson(($this->v)('cancel-audit'))->assertStatus(403);
    ($this->owner)()->patchJson(($this->v)('cancel-audit'), ['reason' => 'We will check it ourselves.'])->assertOk();
    $v = $this->verification->fresh();
    expect($v->decision)->toBe('pending')->and($v->audit_status)->toBe('cancelled')->and($v->audit_fee_status)->toBe('refunded')
        ->and($v->audit_skip_reason)->toBe('We will check it ourselves.')
        ->and((float) $this->organization->fresh()->wallet_balance)->toBe(20000.0)
        ->and($this->milestone->fresh()->status)->toBe('submitted');

    // the PM can no longer report; the owner decides
    ($this->asPm)()->postJson("/api/v1/pm-audits/program/{$v->id}/report", ['recommendation' => 'approve', 'notes' => 'Receipts match the budget.'])->assertStatus(409);
    ($this->owner)()->patchJson(($this->v)('approve'))->assertOk();
});

it('lets the owner team skip a suggested audit and decide the check, recorded in the audit log', function () {
    $this->verification->update(['audit_status' => 'needed']);
    ($this->owner)()->patchJson(($this->v)('approve'))->assertStatus(422);

    ($this->actingAs($this->applicantUser, 'sanctum'))->patchJson(($this->v)('skip-audit'))->assertStatus(403);
    ($this->owner)()->patchJson(($this->v)('skip-audit'), ['reason' => 'No third party on this one.'])->assertOk();
    $v = $this->verification->fresh();
    expect($v->audit_status)->toBe('skipped')->and($v->audit_skip_reason)->toBe('No third party on this one.')
        ->and((int) $v->audit_skipped_by)->toBe($this->orgUser->id);

    $log = OrganizationAuditLog::where('subject_type', 'milestone_verification')->where('subject_id', $v->id)->latest('id')->first();
    expect($log?->changes['audit_status']['new'] ?? null)->toBe('skipped');

    // skipping twice is refused; the decide buttons work again
    ($this->owner)()->patchJson(($this->v)('skip-audit'))->assertStatus(409);
    ($this->owner)()->patchJson(($this->v)('approve'))->assertOk();
});

it('lets the owner team reopen an agreement rejected twice and review it themselves', function () {
    $agreement = MilestonePreAgreement::create(['milestone_id' => $this->milestone->id, 'verification_type' => 'mprv', 'status' => 'final_rejected', 'rejection_count' => 2, 'submitted_by' => $this->applicantUser->id]);
    expect(\App\Service\Program\RepeatedRejectionAudit::required($this->milestone, 'mprv'))->toBeTrue();

    $this->actingAs($this->applicantUser, 'sanctum')->postJson("/api/v1/programs/milestones/{$this->milestone->id}/agreements/mprv/reopen")->assertStatus(403);
    ($this->owner)()->postJson("/api/v1/programs/milestones/{$this->milestone->id}/agreements/mprv/reopen")->assertOk();
    expect($agreement->fresh()->status)->toBe('submitted')
        ->and(\App\Service\Program\RepeatedRejectionAudit::required($this->milestone, 'mprv'))->toBeFalse();

    ($this->owner)()->postJson("/api/v1/programs/milestones/{$this->milestone->id}/agreements/mprv/approve")->assertOk();
    expect($agreement->fresh()->status)->toBe('agreed');
    ($this->owner)()->postJson("/api/v1/programs/milestones/{$this->milestone->id}/agreements/mprv/reopen")->assertStatus(409);
});
