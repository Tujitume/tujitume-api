<?php

namespace Database\Factories;

use App\Models\Auth\User;
use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\Programs\ProgramApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

class MESiteVisitFactory extends Factory
{
    protected $model = MESiteVisit::class;
    public function definition(): array
    {
        return ['checkpoint_id' => MECheckpoint::factory(), 'app_id' => ProgramApplication::factory(), 'reviewer_id' => User::factory(), 'assign_type' => 'internal', 'status' => 'scheduled'];
    }
}
