<?php

use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Communication\Notifications;
use App\Models\Milestones\Milestones;
use App\Models\Programs\MilestoneVerification;
use App\Models\Programs\ProgramMilestone;
use App\Models\Shared\PMAudit;
use Illuminate\Support\Facades\DB;

uses(Tests\Feature\Programs\ProgramTestCase::class);

/*
 * Project Manager Audits: business audits (investors chose the PM) and program audits (a program owner asked the PM)
 * listed together by stage, with each side's actions guarded to the assigned project manager.
 */

beforeEach(function () {
    $this->pm = User::factory()->create(['user_type_id' => 3, 'first_name' => 'Pat']);
    $this->other = User::factory()->create(['user_type_id' => 3]);
    $this->milestone = ProgramMilestone::factory()->create([
        'app_id' => $this->application->id, 'sequence_order' => 1, 'title' => 'Buy equipment', 'amount' => 5000, 'status' => 'submitted',
    ]);
    $this->verification = MilestoneVerification::create([
        'milestone_id' => $this->milestone->id, 'verification_type' => 'mprv', 'decision' => 'pending', 'submitted_by' => $this->applicantUser->id,
    ]);
});

function pmaRequestAudit($test): void
{
    $test->actingAs($test->orgUser, 'sanctum')
        ->patchJson("/api/v1/programs/verifications/{$test->verification->id}/request-audit", [
            'auditor_id' => $test->pm->id, 'decision_notes' => 'Check the receipts',
        ])->assertOk();
}

it('lists a requested program audit on the pre-release page and counts it in the summary', function () {
    pmaRequestAudit($this);

    $rows = $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/pm-audits/pre_release')->assertOk()->json('data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'source' => 'program', 'status' => 'pending', 'milestone_title' => 'Buy equipment',
            'request_notes' => 'Check the receipts', 'can_respond' => true, 'can_report' => false, 'needs_you' => true,
        ]);

    $summary = $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/pm-audits/summary')->assertOk()->json('data');
    expect($summary['pre_release'])->toMatchArray(['total' => 1, 'waiting' => 1])
        ->and($summary['pre_release']['by_source'])->toBe(['business' => 0, 'program' => 1, 'service' => 0, 'capital' => 0])
        ->and($summary['mid_milestone']['total'])->toBe(0);

    // another PM sees nothing, and the source filter narrows the list
    expect($this->actingAs($this->other, 'sanctum')->getJson('/api/v1/pm-audits/pre_release')->json('data'))->toBe([]);
    expect($this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/pm-audits/pre_release?source=business')->json('data'))->toBe([]);
});

it('refuses an unknown stage', function () {
    $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/pm-audits/capital_stuff')->assertNotFound();
});

it('lets only the auditor accept, then report; the owner is told and then decides', function () {
    pmaRequestAudit($this);
    $base = "/api/v1/pm-audits/program/{$this->verification->id}";

    $this->actingAs($this->other, 'sanctum')->postJson("$base/respond", ['action' => 'accept'])->assertForbidden();
    $this->actingAs($this->pm, 'sanctum')->postJson("$base/report", ['recommendation' => 'approve', 'notes' => 'All receipts match.'])->assertStatus(409);

    $before = Notifications::where('receiver_id', $this->orgUser->id)->count();
    $this->actingAs($this->pm, 'sanctum')->postJson("$base/respond", ['action' => 'accept'])->assertOk();
    $this->actingAs($this->pm, 'sanctum')->postJson("$base/respond", ['action' => 'decline'])->assertStatus(409);

    $this->actingAs($this->pm, 'sanctum')->postJson("$base/report", ['recommendation' => 'maybe', 'notes' => 'x'])->assertStatus(422);
    $this->actingAs($this->pm, 'sanctum')->postJson("$base/report", ['recommendation' => 'approve', 'notes' => 'All receipts match the budget.'])->assertOk();

    $v = $this->verification->fresh();
    expect($v->audit_status)->toBe('reported')->and($v->audit_recommendation)->toBe('approve')
        ->and($v->audit_notes)->toBe('All receipts match the budget.')->and($v->audit_completed_at)->not->toBeNull()
        ->and(Notifications::where('receiver_id', $this->orgUser->id)->count())->toBe($before + 2);

    $row = $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/pm-audits/pre_release')->json('data.0');
    expect($row)->toMatchArray(['status' => 'reported', 'recommendation' => 'approve', 'needs_you' => false, 'can_report' => false]);

    // The owner can now decide the audited verification (it used to be stuck at audit_requested)
    $this->actingAs($this->orgUser, 'sanctum')->patchJson("/api/v1/programs/verifications/{$this->verification->id}/approve")->assertOk();
    expect($this->verification->fresh()->decision)->toBe('approved');
    expect($this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/pm-audits/pre_release')->json('data.0.status'))->toBe('approved');

    // and the auditor can no longer act on it
    $this->actingAs($this->pm, 'sanctum')->postJson("$base/report", ['recommendation' => 'reject', 'notes' => 'Changed my mind here.'])->assertStatus(409);
});

it('hands a declined audit back to the owner, who can ask again', function () {
    pmaRequestAudit($this);
    $this->actingAs($this->pm, 'sanctum')->postJson("/api/v1/pm-audits/program/{$this->verification->id}/respond", ['action' => 'decline'])->assertOk();

    $v = $this->verification->fresh();
    expect($v->decision)->toBe('pending')->and($v->audit_status)->toBe('declined')
        ->and($this->milestone->fresh()->status)->toBe('submitted');
    expect($this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/pm-audits/pre_release')->json('data'))->toBe([]);

    // asking again starts a fresh audit
    pmaRequestAudit($this);
    expect($this->verification->fresh()->audit_status)->toBe('pending');
});

it('puts mid-milestone program audits on their own stage page', function () {
    $mid = MilestoneVerification::create([
        'milestone_id' => $this->milestone->id, 'verification_type' => 'mid_milestone', 'decision' => 'audit_requested', 'submitted_by' => $this->applicantUser->id,
        'auditor_id' => $this->pm->id, 'audit_started_at' => now(), 'audit_status' => 'pending',
    ]);
    expect($this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/pm-audits/mid_milestone')->json('data.0.id'))->toBe($mid->id);
    expect($this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/pm-audits/final_approval')->json('data'))->toBe([]);
});

describe('business audits', function () {
    beforeEach(function () {
        $listingId = DB::table('listings')->insertGetId(['name' => 'Green Farm', 'user_id' => $this->applicantUser->id, 'created_at' => now(), 'updated_at' => now()]);
        $msId = DB::table('milestones')->insertGetId(['listing_id' => $listingId, 'title' => 'Phase 1', 'amount' => 1200, 'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit = PMAudit::create(['milestone_id' => $msId, 'type' => 'final_approval', 'status' => 'pending', 'assigned_pm_id' => $this->pm->id, 'candidate_pm_ids' => [$this->pm->id]]);
    });

    it('lists them with the program ones, and only the assigned PM may answer or decide', function () {
        $row = $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/pm-audits/final_approval')->assertOk()->json('data.0');
        expect($row)->toMatchArray(['source' => 'business', 'business_name' => 'Green Farm', 'milestone_title' => 'Phase 1', 'can_respond' => true]);

        $this->actingAs($this->other, 'sanctum')->postJson('/api/v1/milestones/audit-request', ['audit_id' => $this->audit->id, 'action' => 'accept'])->assertForbidden();
        $this->actingAs($this->pm, 'sanctum')->postJson("/api/v1/milestones/audits/{$this->audit->id}/review", ['audit_id' => $this->audit->id, 'action' => 'approved'])->assertStatus(422);

        $this->actingAs($this->pm, 'sanctum')->postJson('/api/v1/milestones/audit-request', ['audit_id' => $this->audit->id, 'action' => 'accept'])->assertOk();
        $this->actingAs($this->pm, 'sanctum')->postJson('/api/v1/milestones/audit-request', ['audit_id' => $this->audit->id, 'action' => 'reject'])->assertStatus(409);
        $this->actingAs($this->other, 'sanctum')->postJson("/api/v1/milestones/audits/{$this->audit->id}/review", ['audit_id' => $this->audit->id, 'action' => 'approved'])->assertForbidden();

        $row = $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/pm-audits/final_approval')->json('data.0');
        expect($row)->toMatchArray(['status' => 'accepted', 'can_decide' => true, 'can_respond' => false]);
    });

    it('shows a declined business audit as declined', function () {
        $this->actingAs($this->pm, 'sanctum')->postJson('/api/v1/milestones/audit-request', ['audit_id' => $this->audit->id, 'action' => 'reject'])->assertOk();
        expect($this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/pm-audits/final_approval')->json('data.0'))
            ->toMatchArray(['status' => 'declined', 'needs_you' => false]);
    });
});

it('finds project managers for the owner, project management providers first, and refuses a non-provider auditor', function () {
    $this->pm->services()->create(['name' => 'Project oversight', 'category' => 'project_management', 'price' => 100, 'active' => 1]);
    $rows = $this->actingAs($this->orgUser, 'sanctum')->getJson('/api/v1/pm-audits/project-managers')->assertOk()->json('data');
    expect($rows[0])->toMatchArray(['id' => $this->pm->id, 'offers_project_management' => true])
        ->and(collect($rows)->pluck('id'))->toContain($this->other->id)->not->toContain($this->orgUser->id);

    $found = $this->actingAs($this->orgUser, 'sanctum')->getJson('/api/v1/pm-audits/project-managers?search=pat')->json('data');
    expect(collect($found)->pluck('id')->all())->toBe([$this->pm->id]);

    $this->actingAs($this->orgUser, 'sanctum')
        ->patchJson("/api/v1/programs/verifications/{$this->verification->id}/request-audit", ['auditor_id' => $this->applicantUser->id])
        ->assertStatus(422);
});
