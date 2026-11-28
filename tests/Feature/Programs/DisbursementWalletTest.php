<?php

namespace Tests\Feature\Programs;

use App\Models\Programs\Disbursement;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\ProgramWallet;
use App\Service\LiprMpesa\ProgramDisbursementService;

/**
 * A disbursement is recorded against the paying program's own wallet (it used to be a fixed wallet, id 1, whatever
 * the program), and a program without a wallet cannot release funds.
 */
class DisbursementWalletTest extends ProgramTestCase
{
    private function milestone(): ProgramMilestone
    {
        $this->application->forceFill(['status' => 'awarded'])->save();

        return ProgramMilestone::create([
            'app_id' => $this->application->id, 'sequence_order' => 1, 'title' => 'Seeds', 'description' => 'Buy seed', 'amount' => 50000,
            'status' => 'pending', 'fund_release_status' => 'approved', 'is_template' => false, 'created_by_role' => 'program_owner',
        ]);
    }

    private function service()
    {
        // Only the money transfer itself is faked
        $service = \Mockery::mock(ProgramDisbursementService::class)->makePartial();
        $service->shouldReceive('disburseToSupplier')->andReturn(['status' => 'ok']);
        $lipr = \Mockery::mock(\App\Service\LiprMpesa\LiprPaymentService::class);
        $lipr->shouldReceive('getReferenceId')->andReturn('REF-1');
        (new \ReflectionProperty(ProgramDisbursementService::class, 'lipr'))->setValue($service, $lipr);

        return $service;
    }

    public function test_the_disbursement_is_recorded_against_the_programs_own_wallet(): void
    {
        // The program's wallet is not wallet 1 (the id the release used to record, whatever the program)
        $this->wallet->forceFill(['program_id' => \App\Models\Programs\Program::factory()->create(['user_id' => $this->orgUser->id])->id])->save();
        $this->wallet = ProgramWallet::factory()->create(['program_id' => $this->program->id, 'status' => 'active', 'balance' => 100000]);
        $this->assertNotSame(1, $this->wallet->id);

        $result = $this->service()->disburse($this->milestone(), 50000, 'pay_from_escrow');

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame($this->wallet->id, (int) Disbursement::where('milestone_id', $result['disbursement']->milestone_id)->value('wallet_id'));
    }

    public function test_a_program_without_a_wallet_cannot_release_funds(): void
    {
        $milestone = $this->milestone();
        $this->wallet->delete();

        $this->expectExceptionMessage('This program has no wallet yet');
        $this->service()->disburse($milestone->fresh(), 50000, 'pay_from_escrow');
    }
}
