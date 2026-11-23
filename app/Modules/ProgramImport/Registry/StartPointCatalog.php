<?php

namespace App\Modules\ProgramImport\Registry;

use App\Modules\ProgramImport\Landing\{DisbursementLanding, FinalizationLanding, FundingSetupLanding, LandingDefinition, MonitoringLanding, ReviewLanding, SetupLanding};

/** What each start point needs, lands and unlocks. Texts are shown by GET /start-points. */
class StartPointCatalog
{
    public const POINTS = ['setup', 'review', 'finalization', 'funding_setup', 'disbursement', 'monitoring'];

    public static function landing(string $startPoint): LandingDefinition
    {
        return match ($startPoint) {
            'setup' => new SetupLanding,
            'review' => new ReviewLanding,
            'finalization' => new FinalizationLanding,
            'funding_setup' => new FundingSetupLanding,
            'disbursement' => new DisbursementLanding,
            'monitoring' => new MonitoringLanding,
            default => throw new \InvalidArgumentException("Unknown start point [$startPoint]"),
        };
    }

    public static function all(): array
    {
        return [
            'setup' => [
                'label' => 'Set up a program',
                'required' => ['program', 'rounds'], 'optional' => ['questions'],
                'summary' => 'A draft, hidden program with its rounds and questions. Nothing is published.',
                'next_steps' => ['Configure the rounds', 'Assign Business Coaches', 'Fund the program wallet', 'Publish'],
            ],
            'review' => [
                'label' => 'Start at review',
                'required' => ['program', 'rounds', 'questions', 'applications'], 'optional' => [],
                'summary' => 'Applications arrive already submitted; earlier rounds are marked finalized. Start scoring or deciding.',
                'next_steps' => ['Assign Business Coaches or score applications', 'Accept or reject (round 1)', 'Finalize the round'],
            ],
            'finalization' => [
                'label' => 'Start at finalization',
                'required' => ['program', 'rounds', 'questions', 'applications', 'scores'], 'optional' => [],
                'summary' => 'Applications arrive scored, with averages and eligibility computed the same way as manual scoring.',
                'next_steps' => ['Finalize the round', 'Confirm awardees'],
            ],
            'funding_setup' => [
                'label' => 'Start at funding setup',
                'required' => ['program', 'winners'], 'optional' => ['rounds', 'applications', 'milestones'],
                'summary' => 'Awardees only. The program is awarded and each awardee is ready for milestone planning.',
                'next_steps' => ['Choose planning mode', 'Create milestone templates', 'Submit and approve the plan', 'Activate'],
            ],
            'disbursement' => [
                'label' => 'Start at disbursement',
                'required' => ['program', 'winners', 'milestones'], 'optional' => ['rounds', 'applications', 'suppliers', 'milestone_suppliers', 'disbursements'],
                'summary' => 'Awardees with active milestones. Historic payments are recorded only; no money moves.',
                'next_steps' => ['Fund escrow for the remainder', 'Verify milestones', 'Release funds', 'Complete milestones'],
            ],
            'monitoring' => [
                'label' => 'Start at monitoring & evaluation',
                'required' => ['program', 'winners', 'milestones', 'disbursements', 'checkpoints'], 'optional' => ['rounds', 'applications', 'submissions', 'site_visits'],
                'summary' => 'Awardees with their milestones, the payments already made and M&E checkpoints, ready for verification and analytics.',
                'next_steps' => ['Send or verify checkpoints', 'Review analytics'],
            ],
        ];
    }

    public static function get(string $startPoint): array
    {
        return self::all()[$startPoint] ?? throw new \InvalidArgumentException("Unknown start point [$startPoint]");
    }

    /** Sheets a start point accepts (required + optional). */
    public static function sheets(string $startPoint): array
    {
        $p = self::get($startPoint);

        return array_values(array_unique(array_merge($p['required'], $p['optional'])));
    }
}
