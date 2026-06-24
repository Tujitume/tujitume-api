<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        //putenv('TERM=unknown'); // Disable advanced terminal styling
        $this->call([
            CategorySeeder::class,
            RoleSeeder::class,
            TaxSeeder::class,
            UserSeeder::class,
            UserTypeSeeder::class,
            AdminSeeder::class,
            ListingSeeder::class,
            ServiceSeeder::class,
            GrantSeeder::class,
            CapitalSeeder::class,
        ]);
        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
    }
}
