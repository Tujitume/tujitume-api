<?php

use App\Models\Programs\Disbursement;
use App\Models\Programs\MilestoneSupplier;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\SupplierDirectory;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Program disbursements', function () {
    it('requires authentication to create a disbursement', function () {
        $milestone = ProgramMilestone::factory()->create(['app_id' => $this->application->id]);
        assertProgramUnauthenticated('POST', "milestones/{$milestone->id}/disbursements");
    });

    it('marks a processing disbursement completed like the payment callback, and names the supplier', function () {
        $milestone = ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'amount' => 250000, 'status' => 'disbursing', 'fund_release_status' => 'processing', 'fund_released' => false]);
        $directory = SupplierDirectory::factory()->create(['legal_name' => 'AquaPure Filtration']);
        $link = MilestoneSupplier::create(['milestone_id' => $milestone->id, 'supplier_id' => $directory->id, 'payment_route' => 'direct_to_supplier']);
        // Made before disbursements were linked to their supplier
        $disbursement = Disbursement::create(['milestone_id' => $milestone->id, 'wallet_id' => $this->wallet->id, 'amount' => 250000, 'currency' => 'KES',
            'recipient_type' => 'supplier', 'payment_method' => 'mpesa_mobile', 'status' => 'processing', 'authorized_by' => $this->orgUser->id]);

        $this->actingAsOrgOwner()->patchJson("/api/v1/programs/disbursements/{$disbursement->id}/mark-completed")->assertOk();

        $disbursement->refresh();
        expect($disbursement->status)->toBe('completed')
            ->and((int) $disbursement->supplier_id)->toBe($link->id)
            ->and($disbursement->disbursed_at)->not->toBeNull();
        $milestone->refresh();
        expect($milestone->fund_release_status)->toBe('released')->and($milestone->status)->toBe('completed')->and((bool) $milestone->fund_released)->toBeTrue();
    });
});
