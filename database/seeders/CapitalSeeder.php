<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use App\Models\Capital\CapitalOffer;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;

class CapitalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();
        $userMinId = User::min('id');
        $userMaxId = User::max('id');

        $startupStages = ['Idea', 'Seed', 'Growth', 'Mature'];
        $sectors = ['Technology', 'Agriculture', 'Health', 'Education', 'Finance'];

        for ($i = 0; $i < 5; $i++) {
            $totalCapital = $faker->randomFloat(2, 50000, 2000000);
            $perStartup = $faker->randomFloat(2, 5000, 100000);
            $availableAmount = $faker->randomFloat(2, 0, $totalCapital);

            CapitalOffer::create([
                'user_id' => 3, //$faker->numberBetween($userMinId, $userMaxId),
                'offer_title' => $faker->catchPhrase,
                'total_capital_available' => $totalCapital,
                'available_amount' => $availableAmount,
                'per_startup_allocation' => $perStartup,
                'milestone_requirements' => $faker->sentence,
                'startup_stage' => $faker->randomElements($startupStages, 3),
                'sectors' => $faker->randomElements($sectors, 3),
                'regions' => $faker->randomElements(['Nairobi,Kenya', 'NY,Usa', 'Turin,Italy', 'China'], 2),
                'impact_objectives' => $faker->sentence,
                'required_docs' => 'Business Plan, ID Proof',
                'offer_brief_file' => $faker->optional()->url . '/offer_brief.pdf',
                'start_date' => $faker->date('Y-m-d', 'now'),
                'end_date' => $faker->date('Y-m-d', '+1 year'),
                'visible' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
