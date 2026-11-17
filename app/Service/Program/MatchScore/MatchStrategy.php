<?php

namespace App\Service\Program\MatchScore;

use App\Models\Programs\ProgramCustomField;

/** One class per match_type. Pure: no database, no eval. Returns how well an answer matches, from 0 to 1. */
interface MatchStrategy
{
    public function match(ProgramCustomField $field, mixed $answer, array $scoring): float;
}
