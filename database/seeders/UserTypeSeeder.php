<?php

namespace Database\Seeders;

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
        DB::table('user_types')->insert([
            ['id' => 1, 'name' => 'investor'],
            ['id' => 2, 'name' => 'grant'],
            ['id' => 3, 'name' => 'capital'],
            ['id' => 4, 'name' => 'business'],
            ['id' => 5, 'name' => 'service'],
        ]);
    }
}
