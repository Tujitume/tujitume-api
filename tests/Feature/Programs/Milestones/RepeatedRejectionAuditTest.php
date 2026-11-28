<?php

use App\Models\Auth\User;
use App\Models\Communication\Notifications;
use App\Models\Programs\MilestonePreAgreement;
use App\Models\Programs\MilestoneVerification;
use App\Models\Programs\ProgramMilestone;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// DECISION-8 (owner 27 Nov): a check sent back twice is audited by a project manager before anyone decides it again.

beforeEach(function () {
    $this->ms = ProgramMilestone::factory()->create([
        'app_id' => $this->application->id, 'sequence_order' => 1, 'status' => 'pending', 'is_template' => false,
        'mprv_required' => true, 'mid_milestone_required' => false, 'final_approval_required' => false, 'fund_release_status' => 'locked',
    ]);
    $this->submit = fn () => $this->actingAs($this->applicantUser, 'sanctum')->postJson("/api/v1/programs/milestones/{$this->ms->id}/verifications", [
        'conflict_of_interest_confirmed' => true, 'funds_usage_confirmed' => true, 'verification_type' => 'mprv', 'additional_declarations' => 'Receipts attached.',
    ]);
});

it('sends the third submission to an audit after two send-backs, and refuses a decision until a project manager is picked', function () {
    foreach ([1, 2] as $round) {
        ($this->submit)()->assertCreated();
        $v = MilestoneVerification::latest('id')->first();
        $this->actingAs($this->orgUser, 'sanctum')->patchJson("/api/v1/programs/verifications/{$v->id}/request-modification", ['decision_notes' => "Fix the receipts ({$round})."])->assertOk();
        $this->ms->update(['status' => 'pending']);
    }

    $res = ($this->submit)()->assertCreated();
    expect($res->json('message'))->toContain('independent project manager');
    $v = MilestoneVerification::latest('id')->first();
    expect($v->audit_status)->toBe('needed')->and($v->decision)->toBe('pending');

    // both sides told
    expect(Notifications::where('receiver_id', $this->orgUser->id)->where('text', 'like', '%Pick a project manager%')->count())->toBe(1)
        ->and(Notifications::where('receiver_id', $this->applicantUser->id)->where('text', 'like', '%independent project manager%')->count())->toBe(1);

    // the owner cannot decide it yet
    $this->actingAs($this->orgUser, 'sanctum')->patchJson("/api/v1/programs/verifications/{$v->id}/approve")
        ->assertStatus(422)->assertJsonFragment(['message' => 'This check was sent back twice, so it needs a project manager audit first. Pick a project manager.']);

    // picking a project manager starts the normal audit
    $pm = User::factory()->create(['user_type_id' => 3]);
    $this->actingAs($this->orgUser, 'sanctum')->patchJson("/api/v1/programs/verifications/{$v->id}/request-audit", ['auditor_id' => $pm->id])->assertOk();
    expect($v->fresh()->decision)->toBe('audit_requested')->and($v->fresh()->audit_status)->toBe('pending');
});

it('does not ask for an audit after a single send-back', function () {
    ($this->submit)()->assertCreated();
    $v = MilestoneVerification::latest('id')->first();
    $this->actingAs($this->orgUser, 'sanctum')->patchJson("/api/v1/programs/verifications/{$v->id}/request-modification", ['decision_notes' => 'Fix the receipts.'])->assertOk();
    $this->ms->update(['status' => 'pending']);

    expect(($this->submit)()->assertCreated()->json('message'))->not->toContain('independent')
        ->and(MilestoneVerification::latest('id')->first()->audit_status)->toBeNull();
});

it('settles a step agreement rejected twice and audits its check', function () {
    $agreement = MilestonePreAgreement::create(['milestone_id' => $this->ms->id, 'verification_type' => 'mprv', 'status' => 'submitted', 'submitted_by' => $this->applicantUser->id]);
    $reject = fn () => $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/milestones/{$this->ms->id}/agreements/mprv/reject", ['rejection_reason' => 'Not acceptable.']);

    $reject()->assertOk();
    expect($agreement->fresh()->status)->toBe('rejected');
    // ISSUE-42: the first rejection tells the applicant what to change
    expect(\App\Models\Communication\Notifications::where('receiver_id', $this->applicantUser->id)->where('text', 'like', '%asked for changes%Not acceptable%')->exists())->toBeTrue();
    $agreement->fresh()->update(['status' => 'submitted']);
    expect($reject()->assertOk()->json('message'))->toContain('rejected a second time');
    expect($agreement->fresh()->status)->toBe('final_rejected')
        ->and(\App\Service\Program\RepeatedRejectionAudit::required($this->ms, 'mprv'))->toBeTrue();

    // an applicant reply no longer reopens it
    $this->actingAs($this->applicantUser, 'sanctum')->postJson("/api/v1/programs/milestones/{$this->ms->id}/agreements/mprv/comment", ['comment' => 'Please look again.']);
    expect($agreement->fresh()->status)->toBe('final_rejected');
});

// C12: on a program a team member created, the organization owner and the program team are told when a check is submitted
it('tells the whole program team, not only the creator, when a check is submitted', function () {
    $creator = User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
    \Illuminate\Support\Facades\DB::table('roles')->insertOrIgnore(['id' => 10001, 'name' => 'super_admin', 'access_types' => '[]']);
    \App\Models\Auth\OrganizationUserRole::create(['user_id' => $creator->id, 'organization_id' => $this->organization->id, 'role_id' => 10001]);
    $this->program->update(['user_id' => $creator->id, 'organization_id' => $this->organization->id]);

    ($this->submit)()->assertCreated();
    foreach ([$creator, $this->orgUser] as $person) {
        expect(Notifications::where('receiver_id', $person->id)->where('text', 'like', '%MPRV%')->exists())->toBeTrue();
    }
    expect(Notifications::where('receiver_id', $this->applicantUser->id)->where('text', 'like', '%submitted the MPRV%')->exists())->toBeFalse();
});
