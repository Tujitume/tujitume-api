<?php

namespace App\Service\Program\MatchScore;

use App\Models\Programs\ProgramCustomField;

class InSetStrategy implements MatchStrategy
{
    public function match(ProgramCustomField $field, mixed $answer, array $scoring): float
    {
        $target = array_map('strval', (array) ($scoring['target'] ?? []));
        if (! $target) {
            return 0.0;
        }
        if ($field->type === 'select') {
            return $answer !== null && in_array((string) $answer, $target, true) ? 1.0 : 0.0;
        }
        $given = array_map('strval', (array) $answer);
        $overlap = count(array_intersect($target, $given));

        return match ($scoring['mode'] ?? 'any') {
            'proportional' => $overlap / count($target),
            'all' => $overlap === count($target) ? 1.0 : 0.0,
            default => $overlap >= 1 ? 1.0 : 0.0,
        };
    }
}
