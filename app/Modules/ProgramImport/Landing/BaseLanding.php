<?php

namespace App\Modules\ProgramImport\Landing;

abstract class BaseLanding implements LandingDefinition
{
    public function program(bool $allMilestonesDone = false): array
    {
        return ['status' => 'draft', 'visible' => false];
    }

    public function roundStatus(int $roundNumber, ?int $targetRound): string
    {
        return 'draft';
    }

    public function application(int $roundNumber, bool $hasScore): array
    {
        return [];
    }

    public function milestone(int $position): array
    {
        return [];
    }

    public function skipped(): array
    {
        return ['Earlier-round history is synthetic; reviewers, site visits and payments are not imported.'];
    }

    /** Past-dated rounds: an imported round is never published through the checklist and never opens to applicants. */
    protected function afterAwardApplication(): array
    {
        return ['status' => 'awarded', 'round_status' => 'advanced', 'planning_mode' => 'locked'];
    }
}
