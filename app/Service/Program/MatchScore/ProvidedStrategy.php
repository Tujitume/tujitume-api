<?php

namespace App\Service\Program\MatchScore;

use App\Models\Programs\ProgramCustomField;

class ProvidedStrategy implements MatchStrategy
{
    public function match(ProgramCustomField $field, mixed $answer, array $scoring): float
    {
        if (is_array($answer)) {
            return count(array_filter($answer, fn ($x) => $x !== null && $x !== '')) > 0 ? 1.0 : 0.0;
        }

        return $answer !== null && trim((string) $answer) !== '' ? 1.0 : 0.0;
    }
}
