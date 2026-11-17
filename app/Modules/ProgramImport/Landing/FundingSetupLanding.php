<?php

namespace App\Modules\ProgramImport\Landing;

class FundingSetupLanding extends BaseLanding
{
    public function startPoint(): string
    {
        return 'funding_setup';
    }

    public function program(bool $allMilestonesDone = false): array
    {
        return ['status' => 'awarded', 'visible' => false];
    }

    public function roundStatus(int $roundNumber, ?int $targetRound): string
    {
        return 'finalized';
    }

    public function application(int $roundNumber, bool $hasScore): array
    {
        return $this->afterAwardApplication() + ['funding_setup_status' => 'not_started'];
    }

    public function milestone(int $position): array
    {
        // Templates: is_template=true, awaiting plan approval and activation.
        return ['is_template' => true, 'status' => 'pending', 'fund_release_status' => 'locked', 'created_by_role' => 'program_owner'];
    }

    public function describe(): array
    {
        return ['program' => 'awarded, hidden', 'rounds' => 'finalized (real history when given, else a synthetic "Imported awardees" round)',
            'applications' => 'awarded, funding setup not started, planning mode locked', 'milestones' => 'templates (is_template) when given'];
    }
}
