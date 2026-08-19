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
            ['id' => 1, 'name' => 'business_owner'],
            ['id' => 2, 'name' => 'investor'],
            ['id' => 3, 'name' => 'service_provider'],
            ['id' => 4, 'name' => 'organization'],
            ['id' => 5, 'name' => 'capital'],
            ['id' => 6, 'name' => 'external_reviewer'],
            ['id' => 7, 'name' => 'admin'],


        ];
        UserType::truncate();
        UserType::insert($types);
    }
}
