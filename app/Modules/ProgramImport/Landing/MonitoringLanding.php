<?php

namespace App\Modules\ProgramImport\Landing;

class MonitoringLanding extends DisbursementLanding
{
    public function startPoint(): string
    {
        return 'monitoring';
    }

    public function program(bool $allMilestonesDone = false): array
    {
        return ['status' => $allMilestonesDone ? 'completed' : 'awarded', 'visible' => false];
    }

    public function describe(): array
    {
        return array_merge(parent::describe(), ['program' => 'awarded (completed when every milestone is done), hidden', 'milestones' => 'optional']);
    }
}
