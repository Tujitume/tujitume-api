<?php

namespace Database\Factories;

use App\Models\Auth\User;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationScoreFactory extends Factory
{
    protected $model = ApplicationScore::class;
    public function definition(): array
    {
        return ['application_id' => ProgramApplication::factory(), 'round_id' => ProgramRound::factory(), 'reviewer_id' => User::factory(), 'criterion_scores' => [['criterion_name' => 'Impact', 'score' => 8]], 'total_score' => 80, 'scored_at' => now()];
    }
}
