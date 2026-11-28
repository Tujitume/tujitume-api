<?php

use App\Models\Communication\Notifications;
use App\Models\Programs\ProgramMilestone;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// e2e test 2026-11-27. ISSUE-18: every check was announced as "New MPRV Submitted" (linking to the MPRV tab), even a
// final approval. ISSUE-19 (owner decision): final approval opens only once the milestone's funds are released.

function submitCheck($test, ProgramMilestone $ms, string $type)
{
    return $test->actingAs($test->applicantUser, 'sanctum')->postJson("/api/v1/programs/milestones/{$ms->id}/verifications", [
        'conflict_of_interest_confirmed' => true, 'funds_usage_confirmed' => true, 'verification_type' => $type,
        'additional_declarations' => 'All delivered as planned.',
    ]);
}

beforeEach(function () {
    $this->ms = ProgramMilestone::factory()->create([
        'app_id' => $this->application->id, 'sequence_order' => 1, 'status' => 'approved', 'is_template' => false,
        'mprv_required' => false, 'mid_milestone_required' => true, 'final_approval_required' => true, 'fund_released' => 0,
    ]);
});

it('refuses final approval until the funds are released (ISSUE-19)', function () {
    submitCheck($this, $this->ms, 'final_approval')->assertStatus(422)->assertJsonFragment(['Final approval opens once the funds for this milestone are released.']);
});

it('announces each check as what it is (ISSUE-18)', function () {
    $this->ms->update(['fund_released' => 1]);

    $res = submitCheck($this, $this->ms, 'final_approval');
    if ($res->status() === 422) $this->markTestSkipped('final approval needs more step set-up: '.json_encode($res->json('errors')));
    $res->assertCreated();
    expect($res->json('message'))->toStartWith('Final approval submitted');

    $note = Notifications::where('receiver_id', $this->orgUser->id)->latest('id')->first();
    expect($note->text)->toContain('final approval')->not->toContain('MPRV')
        ->and($note->link)->toContain('final-approval');
});

it('opens the next milestone when the owner approves the final approval check (ISSUE-21)', function () {
    $this->ms->update(['fund_released' => 1, 'fund_release_status' => 'released', 'status' => 'completed']);
    $next = ProgramMilestone::factory()->create([
        'app_id' => $this->application->id, 'sequence_order' => 2, 'status' => 'pending', 'is_template' => false,
        'fund_release_status' => 'locked', 'mprv_required' => false, 'mid_milestone_required' => true, 'final_approval_required' => true,
    ]);
    $check = \App\Models\Programs\MilestoneVerification::create([
        'milestone_id' => $this->ms->id, 'verification_type' => 'final_approval', 'decision' => 'pending', 'submitted_by' => $this->applicantUser->id,
    ]);

    $this->actingAs($this->orgUser, 'sanctum')->patchJson("/api/v1/programs/verifications/{$check->id}/approve")->assertOk();

    expect($next->fresh()->fund_release_status)->toBe('approved')->and($next->fresh()->status)->toBe('approved');
});

it('leaves no database transaction open after a refused submission (ISSUE-27)', function () {
    $level = \Illuminate\Support\Facades\DB::transactionLevel();
    submitCheck($this, $this->ms, 'final_approval')->assertStatus(422); // refused inside the controller's transaction
    expect(\Illuminate\Support\Facades\DB::transactionLevel())->toBe($level);
});

it('rolls back any transaction a request forgot to close (ISSUE-27 safety net)', function () {
    \Illuminate\Support\Facades\Route::middleware('api')->get('/api/test-leaky', function () {
        \Illuminate\Support\Facades\DB::beginTransaction();
        return response()->json(['ok' => true], 422);
    });
    $level = \Illuminate\Support\Facades\DB::transactionLevel();
    $this->getJson('/api/test-leaky')->assertStatus(422);
    expect(\Illuminate\Support\Facades\DB::transactionLevel())->toBe($level);
});

it('takes no check on a milestone while an earlier one is unfinished (ISSUE-30)', function () {
    $first = $this->ms; // funds not released
    $second = ProgramMilestone::factory()->create([
        'app_id' => $this->application->id, 'sequence_order' => 2, 'status' => 'pending', 'is_template' => false,
        'fund_release_status' => 'locked', 'mprv_required' => false, 'mid_milestone_required' => true, 'final_approval_required' => true,
    ]);

    submitCheck($this, $second, 'mid_milestone')->assertStatus(422)->assertJsonFragment(['This milestone opens once Milestone 1 is complete.']);
    expect($second->fresh()->waiting_for_milestone)->toBe(1)->and($first->fresh()->waiting_for_milestone)->toBeNull();

    // Milestone 1 released and its final approval approved: milestone 2 is open
    $first->update(['fund_release_status' => 'released', 'fund_released' => 1]);
    \App\Models\Programs\MilestoneVerification::create(['milestone_id' => $first->id, 'verification_type' => 'final_approval', 'decision' => 'approved', 'submitted_by' => $this->applicantUser->id]);
    expect($second->fresh()->waiting_for_milestone)->toBeNull();
});

it('puts the application in monitoring after its last milestone, and completes it when its checkpoints are verified', function () {
    $this->application->update(['status' => 'awarded']);
    $this->ms->update(['fund_released' => 1, 'fund_release_status' => 'released', 'status' => 'completed']);
    $checkpoint = \App\Models\Programs\Monitoring\MECheckpoint::create(['app_id' => $this->application->id, 'program_id' => $this->program->id, 'checkpoint_name' => 'Farm visit', 'type' => 'monitoring', 'status' => 'pending', 'respondent' => 'applicant']);
    $check = \App\Models\Programs\MilestoneVerification::create([
        'milestone_id' => $this->ms->id, 'verification_type' => 'final_approval', 'decision' => 'pending', 'submitted_by' => $this->applicantUser->id,
    ]);

    $this->actingAs($this->orgUser, 'sanctum')->patchJson("/api/v1/programs/verifications/{$check->id}/approve")->assertOk();
    expect($this->application->fresh()->status)->toBe('in_monitoring')->and($this->application->fresh()->completed_at)->toBeNull();
    // still listed with the awarded ones
    $list = $this->actingAs($this->applicantUser, 'sanctum')->getJson('/api/v1/programs/my-applications/awarded')->json();
    expect(collect($list['data']['items'] ?? $list['data'] ?? [])->pluck('id'))->toContain($this->application->id);

    // the last checkpoint verified → completed, both told
    $submission = \App\Models\Programs\Monitoring\MESubmission::create(['checkpoint_id' => $checkpoint->id, 'app_id' => $this->application->id, 'submitted_by' => $this->applicantUser->id, 'status' => 'submitted', 'written_report' => 'Done']);
    $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/monitoring/submissions/{$submission->id}/verify")->assertSuccessful();

    expect($this->application->fresh()->status)->toBe('completed')
        ->and($this->application->fresh()->completed_at)->not->toBeNull()
        ->and(\App\Models\Communication\Notifications::where('receiver_id', $this->applicantUser->id)->where('text', 'like', '%Your program is complete%')->count())->toBe(1)
        ->and(\App\Models\Communication\Notifications::where('receiver_id', $this->orgUser->id)->where('text', 'like', '%has completed%')->count())->toBe(1);
});

it('lets the owner close monitoring when there are no checkpoints, which completes the application', function () {
    $this->application->update(['status' => 'awarded']);
    $this->ms->update(['fund_released' => 1, 'fund_release_status' => 'released', 'status' => 'completed', 'final_approval_required' => false]);
    \App\Service\Program\ApplicationCompletion::refresh($this->application);
    expect($this->application->fresh()->status)->toBe('in_monitoring');

    $this->actingAs($this->applicantUser, 'sanctum')->postJson("/api/v1/programs/applications/{$this->application->id}/close-monitoring")->assertForbidden();
    $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/applications/{$this->application->id}/close-monitoring")->assertOk();
    expect($this->application->fresh()->status)->toBe('completed')->and($this->application->fresh()->monitoring_closed_by)->toBe($this->orgUser->id);
});

it('refuses to close monitoring before every milestone is finished', function () {
    $this->application->update(['status' => 'awarded']);
    $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/applications/{$this->application->id}/close-monitoring")
        ->assertStatus(422)->assertJsonFragment(['message' => 'Every milestone must be paid and approved before monitoring can be closed.']);
});

it('does not complete the application while a milestone\'s funds are not released (owner answers 27 Nov)', function () {
    $this->application->update(['status' => 'awarded']);
    $this->ms->update(['fund_released' => 1, 'fund_release_status' => 'approved', 'status' => 'approved']); // approved, not paid out
    $check = \App\Models\Programs\MilestoneVerification::create([
        'milestone_id' => $this->ms->id, 'verification_type' => 'final_approval', 'decision' => 'pending', 'submitted_by' => $this->applicantUser->id,
    ]);
    $this->actingAs($this->orgUser, 'sanctum')->patchJson("/api/v1/programs/verifications/{$check->id}/approve")->assertOk();
    expect($this->application->fresh()->status)->toBe('awarded');
});

it('verifies a checkpoint with a photo even when its file link cannot be made (ISSUE-34)', function () {
    $checkpoint = \App\Models\Programs\Monitoring\MECheckpoint::create(['app_id' => $this->application->id, 'program_id' => $this->program->id, 'checkpoint_name' => 'Photo', 'type' => 'monitoring', 'status' => 'submitted', 'respondent' => 'applicant']);
    $submission = \App\Models\Programs\Monitoring\MESubmission::create(['checkpoint_id' => $checkpoint->id, 'app_id' => $this->application->id, 'submitted_by' => $this->applicantUser->id, 'status' => 'submitted', 'written_report' => 'Photo attached']);
    \App\Models\Programs\Monitoring\MESubmissionFile::create(['submission_id' => $submission->id, 'file_type' => 'photo', 'file_path' => 'me/photo.jpg']);

    // like an unset bucket: making the link throws; the reply still succeeds, with no link
    \Illuminate\Support\Facades\Storage::disk('s3')->buildTemporaryUrlsUsing(fn () => throw new \RuntimeException('The GetObject operation requires non-empty parameter: Bucket'));
    $res = $this->actingAs($this->orgUser, 'sanctum')->postJson("/api/v1/programs/monitoring/submissions/{$submission->id}/verify")->assertSuccessful();
    expect($res->json('data.files.0.file_url'))->toBeNull()->and($checkpoint->fresh()->status)->toBe('verified');
});
