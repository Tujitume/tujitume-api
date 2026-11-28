<?php

use App\Models\Programs\MilestoneSupplier;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\SupplierDirectory;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// milestones/{milestone}/suppliers/{supplier}: {supplier} is the milestone's supplier link, and only that milestone's
// link is touched (the methods used to read the milestone id as the link id).
beforeEach(function () {
    $this->milestone = ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => 1]);
    $this->other = ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => 2]);
    $directory = SupplierDirectory::factory()->create();
    $this->link = MilestoneSupplier::query()->create(['milestone_id' => $this->milestone->id, 'supplier_id' => $directory->id, 'assignment_type' => 'primary', 'added_by' => 'program_owner']);
    // A link whose id equals the milestone id must never be the one removed
    $this->decoy = MilestoneSupplier::query()->create(['milestone_id' => $this->other->id, 'supplier_id' => $directory->id, 'assignment_type' => 'primary', 'added_by' => 'program_owner']);
});

it('removes exactly the link named, on its own milestone', function () {
    $this->actingAsOrgOwner()->deleteJson("/api/v1/programs/milestones/{$this->milestone->id}/suppliers/{$this->link->id}")->assertOk();

    expect(MilestoneSupplier::find($this->link->id))->toBeNull()
        ->and(MilestoneSupplier::find($this->decoy->id))->not->toBeNull();
});

it('does not find a link through another milestone', function () {
    $this->actingAsOrgOwner()->deleteJson("/api/v1/programs/milestones/{$this->milestone->id}/suppliers/{$this->decoy->id}")->assertNotFound();
    expect(MilestoneSupplier::find($this->decoy->id))->not->toBeNull();
});

it('keeps people outside the program out', function () {
    $this->actingAsReviewer()->deleteJson("/api/v1/programs/milestones/{$this->milestone->id}/suppliers/{$this->link->id}")->assertForbidden();
    $this->actingAsReviewer()->getJson("/api/v1/programs/milestones/{$this->milestone->id}/suppliers/{$this->link->id}")->assertForbidden();
    $this->actingAsOrgOwner()->getJson("/api/v1/programs/milestones/{$this->milestone->id}/suppliers/{$this->link->id}")->assertOk()
        ->assertJsonPath('data.id', $this->link->id);
});
