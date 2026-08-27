<?php

namespace Database\Factories;

use App\Models\Auth\User;
use App\Models\Programs\SupplierDirectory;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierDirectoryFactory extends Factory
{
    protected $model = SupplierDirectory::class;
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'legal_name' => fake()->company(), 'contact_person' => fake()->name(), 'email' => fake()->companyEmail(), 'supplier_type' => 'materials', 'payment_method' => 'bank_transfer', 'is_active' => true];
    }
}
