<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Admin::create([
            'email' => 'stevemonitoring.gathirus@gmail.com',
            'password' => Hash::make('adminadmin'),
            'name' => 'Steve Waruta',

        ]);

        Admin::create([
            'email' => 'tottenham266@gmail.com',
            'password' => Hash::make('adminadmin'),
            'name' => 'Nurul Kabir',

        ]);
    }
}
