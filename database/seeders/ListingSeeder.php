<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;

class ListingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();
        $user = User::all();

        $socialImpactAreas = ['Gender-led', 'Local-sourcing', 'Youth-led', 'Diversity'];

        $keywords = ['business', 'office', 'startup', 'company'];
        $randKeyword = $keywords[array_rand($keywords)];
        $image = 'https://picsum.photos/600/400?random='.rand(1,1000);

        for ($i = 0; $i < 5; $i++) {
            Listing::create([
                'user_id' => 1, //$faker->numberBetween($user->min('id'), $user->max('id')),
                'name' => $faker->company,
                'category' => $faker->randomElement(['Auto', 'Education', 'Renewable-Energy', 'Agriculture', 'Fashion', 'Sports & Gaming']),
                'image' => 'https://picsum.photos/600/400?random='.rand(1,1000),
                'details' => $faker->text(300),
                'location' => $faker->city . ', ' . $faker->country,
                'lat' => $faker->latitude( -90, 90 ),
                'lng' => $faker->longitude( -180, 180 ),
                'contact' => $faker->phoneNumber,
                'contact_mail' => $faker->unique()->safeEmail,
                'investment_needed' => $faker->numberBetween(10000, 1000000),
                'amount_collected' => $faker->randomFloat(2, 0, 100000),
                'invest_count' => $faker->numberBetween(0, 50),
                'share' => $faker->numberBetween(1, 100),
                'y_turnover' => $faker->randomElement([
                    '0-10,000',
                    '10,001-50,000',
                    '50,001-100,000',
                    '100,001-250,000',
                    '250,001-500,000'
                ]),
                'pin' => $faker->postcode,
                'identification' => $faker->uuid,
                'document' => $faker->fileExtension,
                'video' => $faker->url,
                'reason' => $faker->sentence,
                'stage' => $faker->randomElement(['Seed', 'Growth', 'Established']),
                'social_impact_areas' => $faker->randomElements($socialImpactAreas, rand(1, 3)),
                'investors_fee' => $faker->numberBetween(1, 20),
                'yeary_fin_statement' => $faker->word . '.pdf',
                'id_no' => $faker->swiftBicNumber,
                'tax_pin' => $faker->ean13,
                'rating' => $faker->randomFloat(2, 0, 5),
                'rating_count' => $faker->numberBetween(0, 1000),
                'active' => $faker->boolean,
                'threshold_met' => $faker->boolean,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
