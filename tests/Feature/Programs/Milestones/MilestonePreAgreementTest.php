<?php

use App\Models\Programs\MilestonePreAgreement;
use App\Models\Programs\ProgramMilestone;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Milestone pre-agreements', function () {
    it('requires authentication to comment', function () {
        $milestone = ProgramMilestone::factory()->create(['app_id' => $this->application->id]);
        assertProgramUnauthenticated('POST', "milestones/{$milestone->id}/agreements/mprv/comment");
    });

    it('lets the program owner approve and reject a submitted agreement, recording who did it', function () {
        $milestone = ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'mprv_required' => true, 'final_approval_required' => true]);
        foreach (['mprv', 'final_approval'] as $type) {
            MilestonePreAgreement::create(['milestone_id' => $milestone->id, 'verification_type' => $type, 'status' => 'submitted', 'submitted_by' => $this->applicantUser->id]);
        }

        $this->actingAsOrgOwner()
            ->postJson("/api/v1/programs/milestones/{$milestone->id}/agreements/mprv/approve", ['comment' => 'Agreed.'])
            ->assertOk();
        $this->assertDatabaseHas('milestone_pre_agreements', ['milestone_id' => $milestone->id, 'verification_type' => 'mprv', 'status' => 'agreed', 'reviewed_by' => $this->orgUser->id]);

        $this->actingAsOrgOwner()
            ->postJson("/api/v1/programs/milestones/{$milestone->id}/agreements/final_approval/reject", ['rejection_reason' => 'Please add photos to the final check.'])
            ->assertOk();
        $this->assertDatabaseHas('milestone_pre_agreements', ['milestone_id' => $milestone->id, 'verification_type' => 'final_approval', 'status' => 'rejected']);
    });

    it('sets the awarded amount and date when the owner activates the funding plan', function () {
        $this->application->update(['status' => 'awarded', 'planning_mode' => 'locked', 'total_amount_requested' => 500000, 'awarded_amount' => null, 'awarded_at' => null]);
        ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'is_template' => true, 'sequence_order' => 1, 'amount' => 300000, 'mprv_required' => false, 'mid_milestone_required' => false, 'final_approval_required' => false]);
        ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'is_template' => true, 'sequence_order' => 2, 'amount' => 150000, 'mprv_required' => false, 'mid_milestone_required' => false, 'final_approval_required' => false]);

        $this->actingAsOrgOwner()
            ->postJson("/api/v1/programs/applications/{$this->application->id}/milestones/activate")
            ->assertOk()->assertJsonPath('data.total_amount', 450000);

        $app = $this->application->fresh();
        expect($app->funding_setup_status)->toBe('completed')
            ->and((float) $app->awarded_amount)->toBe(450000.0)
            ->and($app->awarded_at)->not->toBeNull();
    });
});
