<?php

use App\Models\Programs\MilestoneVerification;
use App\Models\Programs\ProgramMilestone;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// Approving a milestone's final approval opens the next one. It used to write a program_milestones.notes column that
// does not exist, so the save failed and the next milestone never unlocked.

it('unlocks the next milestone when the final approval is approved', function () {
    $first = ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => 1, 'is_template' => false,
        'status' => 'approved', 'fund_release_status' => 'released']);
    $next = ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => 2, 'is_template' => false,
        'status' => 'pending', 'fund_release_status' => 'locked', 'mprv_required' => false]);
    $check = MilestoneVerification::create(['milestone_id' => $first->id, 'verification_type' => 'final_approval',
        'decision' => 'pending', 'submitted_by' => $this->applicantUser->id]);

    $this->actingAsOrgOwner()->patchJson("/api/v1/programs/verifications/{$check->id}/approve")->assertOk();

    $next->refresh();
    expect($next->fund_release_status)->toBe('approved')
        ->and($next->status)->toBe('approved')
        ->and($next->verification_notes)->toBe('Unlocked: Previous milestone completed');
});
