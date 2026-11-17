<?php

namespace App\Modules\ProgramImport\Landing;

class FinalizationLanding extends ReviewLanding
{
    public function startPoint(): string
    {
        return 'finalization';
    }

    public function application(int $roundNumber, bool $hasScore): array
    {
        $base = parent::application($roundNumber, $hasScore);
        if ($hasScore) {
            // Rounds 1 keeps status pending; score values come from ScoreAverager::apply (round_status=scored, average, eligibility).
            $base['round_status'] = 'scored';
        }

        return $base;
    }

    public function describe(): array
    {
        return array_merge(parent::describe(), ['applications' => 'scored: average score and eligibility computed exactly as manual scoring']);
    }
}
