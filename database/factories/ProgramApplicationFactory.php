<?php

namespace Database\Factories;

use App\Models\Auth\User;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProgramApplicationFactory extends Factory
{
    protected $model = ProgramApplication::class;
    public function definition(): array
    {
        $owner = User::factory();
        return ['program_id' => Program::factory(), 'user_id' => User::factory(), 'program_owner_id' => $owner, 'startup_name' => fake()->company(), 'sector' => 'Agriculture', 'headquarters_location' => fake()->city(), 'total_amount_requested' => 10000, 'status' => 'pending', 'planning_mode' => 'locked'];
    }
}
