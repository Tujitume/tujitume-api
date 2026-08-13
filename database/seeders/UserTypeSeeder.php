<?php

namespace Database\Seeders;

use App\Models\Auth\UserType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            ['id' => 1, 'name' => 'investor'],
            ['id' => 2, 'name' => 'industry'],
            ['id' => 3, 'name' => 'capitals'],
            ['id' => 4, 'name' => 'business_owner'],
            ['id' => 5, 'name' => 'service_provider'],
            //['id' => 6, 'name' => 'internal_reviewer'],
            ['id' => 6, 'name' => 'external_reviewer'],
            ['id' => 7, 'name' => 'admin'],
        ];
        UserType::truncate();
        UserType::insert($types);
    }
}
