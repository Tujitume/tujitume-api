<?php

namespace Database\Factories;

use App\Models\Auth\User;
use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\Monitoring\MESubmission;
use App\Models\Programs\ProgramApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

class MESubmissionFactory extends Factory
{
    protected $model = MESubmission::class;
    public function definition(): array
    {
        return ['checkpoint_id' => MECheckpoint::factory(), 'app_id' => ProgramApplication::factory(), 'submitted_by' => User::factory(), 'written_report' => fake()->paragraph(), 'status' => 'submitted', 'submitted_at' => now()];
    }
}
