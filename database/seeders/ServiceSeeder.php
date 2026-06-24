<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use App\Models\Services\Services;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();
        $user = User::all();

        $keywords = ['business', 'office', 'startup', 'company'];
        $randKeyword = $keywords[array_rand($keywords)];
        $image = 'https://picsum.photos/600/400?random='.rand(1,1000);
        $socialImpactAreas = ['Gender-led', 'Local-sourcing', 'Youth-led', 'Diversity'];

        foreach (range(1, 5) as $i) {
            Services::create([
                'user_id'                => 5, //$faker->numberBetween($user->min('id'), $user->max('id')),
                'name'                   => $faker->company,
                'image'                  => 'https://picsum.photos/600/400?random='.rand(1,1000),
                'price'                  => $faker->numberBetween(100, 10000),
                'category'               => $faker->word,
                'business_sector_focus'  => $faker->randomElements(['Retail', 'Education', 'Technology', 'Auto'], 3),
                'details'                => $faker->paragraph,
                'location'               => $faker->address,
                'social_impact_areas'    => $faker->randomElements($socialImpactAreas, rand(1, 3)),
                'lat'                    => $faker->latitude,
                'lng'                    => $faker->longitude,
                'pin'                    => strtoupper($faker->bothify('PIN####')),
                'identification'         => strtoupper($faker->bothify('ID#####')),
                'document'               => $faker->filePath(),
                'video'                  => $faker->url,
                'rating'                 => $faker->randomFloat(2, 1, 5),
                'rating_count'           => $faker->numberBetween(1, 1000),
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);
        }
    }
}
