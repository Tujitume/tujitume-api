<?php

namespace Database\Factories;

use App\Models\Auth\User;
use App\Models\Users\InvestorProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvestorProfileFactory extends Factory
{
    protected $model = InvestorProfile::class;
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'interested_sectors' => ['Agriculture'], 'stage' => ['seed']];
    }
}
