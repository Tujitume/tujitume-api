<?php

use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\ProgramMilestone;
use Illuminate\Support\Facades\DB;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Program analytics', function () {
    it('reports selection and delivery operations from the program lifecycle', function () {
        $milestone = ProgramMilestone::factory()->create([
            'app_id' => $this->application->id,
            'is_template' => false,
            'status' => 'completion_approved',
            'fund_release_status' => 'released',
            'amount' => 25000,
            'estimated_completion_date' => now()->subDay()->toDateString(),
        ]);
        $checkpoint = MECheckpoint::factory()->create([
            'app_id' => $this->application->id,
            'program_id' => $this->program->id,
            'status' => 'verified',
            'require_site_visit' => true,
            'sent_at' => now(),
        ]);
        DB::table('m_e_site_visits')->insert([
            'checkpoint_id' => $checkpoint->id,
            'app_id' => $this->application->id,
            'reviewer_id' => $this->reviewerUser->id,
            'assign_type' => 'internal',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('disbursements')->insert([
            'milestone_id' => $milestone->id,
            'amount' => 25000,
            'currency' => 'KES',
            'payment_method' => 'other',
            'status' => 'completed',
            'supplier_confirmed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAsOrgOwner()->getJson('/api/v1/programs/analytics');

        $response->assertOk()
            ->assertJsonPath('data.operations.rounds.total', 1)
            ->assertJsonPath('data.operations.rounds.by_status.published', 1)
            ->assertJsonPath('data.operations.milestones.total', 1)
            ->assertJsonPath('data.operations.milestones.completed', 1)
            ->assertJsonPath('data.operations.milestones.release_by_status.released', 1)
            ->assertJsonPath('data.operations.monitoring.checkpoints.total', 1)
            ->assertJsonPath('data.operations.monitoring.checkpoints.by_status.verified', 1)
            ->assertJsonPath('data.operations.monitoring.site_visits.completed', 1)
            ->assertJsonPath('data.operations.disbursements.by_status.completed', 1)
            ->assertJsonPath('data.operations.disbursements.completed_amount_by_currency.0.currency', 'KES')
            ->assertJsonPath('data.operations.disbursements.completed_amount_by_currency.0.amount', 25000);
    });
});
