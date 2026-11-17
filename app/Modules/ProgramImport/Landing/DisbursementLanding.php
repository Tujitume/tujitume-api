<?php

namespace App\Modules\ProgramImport\Landing;

class DisbursementLanding extends FundingSetupLanding
{
    public function startPoint(): string
    {
        return 'disbursement';
    }

    public function application(int $roundNumber, bool $hasScore): array
    {
        return ['funding_setup_status' => 'completed'] + $this->afterAwardApplication();
    }

    /** Mirrors activateTemplates: first milestone approved, the rest locked. Released ones come from the file (record-only). */
    public function milestone(int $position): array
    {
        return ['is_template' => false, 'status' => 'pending', 'fund_release_status' => $position === 1 ? 'approved' : 'locked', 'created_by_role' => 'program_owner'];
    }

    public function describe(): array
    {
        return array_merge(parent::describe(), [
            'applications' => 'awarded, funding setup completed',
            'milestones' => 'active (not templates); first approved, others locked unless the file says released',
        ]);
    }

    public function skipped(): array
    {
        return array_merge(parent::skipped(), ['Historic payments are recorded only: no wallet, escrow or program balance change.']);
    }
}
