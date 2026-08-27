<?php

namespace Database\Factories;

use App\Models\Auth\User;
use App\Models\Users\ServiceProviderProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceProviderProfileFactory extends Factory
{
    protected $model = ServiceProviderProfile::class;
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'supplier_type' => 'consultant', 'work_mode' => 'hybrid', 'service_areas' => ['remote']];
    }
}
