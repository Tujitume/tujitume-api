<?php

namespace App\Service\Program\MatchScore;

use App\Models\Programs\ProgramCustomField;

class EqualsStrategy implements MatchStrategy
{
    public function match(ProgramCustomField $field, mixed $answer, array $scoring): float
    {
        return $answer !== null && (bool) $answer === (bool) ($scoring['target'] ?? null) ? 1.0 : 0.0;
    }
}
