<?php

namespace Database\Factories;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return ['owner_user_id' => User::factory(), 'name' => fake()->company(), 'email' => fake()->companyEmail(), 'organization_type' => 'company', 'status' => 'active'];
    }
}
