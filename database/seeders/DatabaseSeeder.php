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
            ProgramIndustrySeeder::class,
            RoleSeeder::class,
            PlatformSettingSeeder::class,
            UserTypeSeeder::class,
            UserSeeder::class,
            ProgramIndustrySeeder::class,
            OrganizationSeeder::class,
            AdminSeeder::class,
            ListingSeeder::class,
            ServiceSeeder::class,
        ]);
        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
    }
}

// To run all seeders with tinker, use the following command:
// php artisan tinker, "fakerphp/faker": "^1.9.1" if needed
// foreach ([CategorySeeder::class,ProgramIndustrySeeder::class,RoleSeeder::class,PlatformSettingSeeder::class,UserTypeSeeder::class,UserSeeder::class,OrganizationSeeder::class,AdminSeeder::class,ListingSeeder::class,ServiceSeeder::class] as $seeder) app()->make($seeder)->run();
