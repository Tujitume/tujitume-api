<?php

namespace Database\Factories;

use App\Models\Auth\User;
use App\Models\Programs\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProgramFactory extends Factory
{
    protected $model = Program::class;
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'program_title' => fake()->sentence(4), 'total_program_amount' => 100000, 'available_amount' => 100000, 'funding_per_business' => 10000, 'program_type' => 'single_round', 'total_rounds' => 1, 'status' => 'draft', 'currency' => 'USD'];
    }
}
