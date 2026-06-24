<?php

namespace Database\Seeders;

use App\Models\Auth\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            ['id' => 10001, 'name' => 'admin', 'access_types' =>  json_encode(['all'])],
            ['id' => 10002, 'name' => 'editor', 'access_types' => json_encode(['create','edit','view'])],
            ['id' => 10003, 'name' => 'viewer', 'access_types' => json_encode(['view'])],
        ];
        Role::query()->insert($roles);

    }
}
