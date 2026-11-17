<?php

namespace App\Service\Program\MatchScore;

use App\Models\Programs\ProgramCustomField;

class RangeStrategy implements MatchStrategy
{
    public function match(ProgramCustomField $field, mixed $answer, array $scoring): float
    {
        if ($answer === null || $answer === '') {
            return 0.0;
        }
        $t = $scoring['target'] ?? [];
        $v = $field->type === 'date' ? (string) $answer : (float) $answer;
        $min = $t['min'] ?? null;
        $max = $t['max'] ?? null;
        $cast = fn ($x) => $field->type === 'date' ? (string) $x : (float) $x;
        if ($min !== null && $v < $cast($min)) {
            return 0.0;
        }
        if ($max !== null && $v > $cast($max)) {
            return 0.0;
        }

        return 1.0;
    }
}
