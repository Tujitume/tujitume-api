<?php

namespace Database\Factories;

use App\Models\Programs\Program;
use App\Models\Programs\Rounds\ProgramRound;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProgramRoundFactory extends Factory
{
    protected $model = ProgramRound::class;
    public function definition(): array
    {
        return ['program_id' => Program::factory(), 'round_name' => 'Application review', 'round_number' => 1, 'rubric_mode' => 'weighted', 'scoring_criteria' => [['name' => 'Impact', 'weight' => 100, 'score_range' => 10]], 'advancement_mode' => 'manual', 'status' => 'draft'];
    }
}
