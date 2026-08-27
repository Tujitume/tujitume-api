<?php

namespace Database\Factories;

use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProgramMilestoneFactory extends Factory
{
    protected $model = ProgramMilestone::class;
    public function definition(): array
    {
        return ['app_id' => ProgramApplication::factory(), 'sequence_order' => 1, 'title' => fake()->sentence(3), 'amount' => 10000, 'purpose_type' => 'capex', 'status' => 'pending', 'is_template' => true, 'created_by_role' => 'program_owner'];
    }
}
