<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use App\Models\Grants\Grant;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;

class GrantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();
        $user = User::all();

        $grantFocuses = ['Health', 'Education', 'Environment', 'Technology', 'Agriculture'];
        $startupStages = ['Idea', 'Growth', 'Mature', 'Seed', 'Series A'];

        for ($i = 0; $i < 5; $i++) {
            $totalAmount = $faker->randomFloat(2, 10000, 1000000);
            $fundingPerBusiness = $faker->randomFloat(2, 1000, 50000);
            $availableAmount = $faker->randomFloat(2, 0, $totalAmount);

            Grant::create([
                'user_id' => 2, //$faker->numberBetween($user->min('id'), $user->max('id')),
                'grant_title' => $faker->catchPhrase,
                'total_grant_amount' => $totalAmount,
                'available_amount' => $availableAmount,
                'funding_per_business' => $fundingPerBusiness,
                'eligibility_criteria' => $faker->paragraph,
                'required_documents' => 'ID Proof, Business Plan',
                'application_deadline' => $faker->date('Y-m-d', '+1 year'),
                'grant_focus' => $faker->randomElements($grantFocuses, $count = 3),
                'regions' => $faker->randomElements(['Nairobi,Kenya', 'NY,Usa', 'Turin,Italy', 'China'], 3),
                'startup_stage_focus' => $faker->randomElements($startupStages, 3),
                'impact_objectives' => $faker->sentence,
                'evaluation_criteria' => $faker->sentence,
                'grant_brief_pdf' => $faker->optional()->url . '/grant_brief.pdf',
                'start_date' => $faker->date('Y-m-d', 'now'),
                'end_date' => $faker->date('Y-m-d', '+1 year'),
                'visible' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
