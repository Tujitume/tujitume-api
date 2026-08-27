<?php

namespace Database\Factories;

use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\ProgramApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

class MECheckpointFactory extends Factory
{
    protected $model = MECheckpoint::class;
    public function definition(): array
    {
        return ['app_id' => ProgramApplication::factory(), 'program_id' => \App\Models\Programs\Program::factory(), 'checkpoint_name' => fake()->sentence(3), 'type' => 'monitoring', 'status' => 'pending', 'display_order' => 1];
    }
}
