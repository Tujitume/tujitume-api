<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use App\Models\Capital\CapitalProfile;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        try {
            // 1. Business Owner (user_type_id = 1)
            User::updateOrCreate([
                'email' => 'tottenham266@gmail.com',
            ], [
                'first_name' => 'Harry',
                'last_name' => 'Kane',
                'gender' => 'M',
                'dob' => $faker->date('Y-m-d'),
                'user_type_id' => 1,
                'email' => 'tottenham266@gmail.com',
                'email_verified_at' => now(),
                'password' => Hash::make('11111111'),
                'country' => $faker->countryCode,
                'city' => $faker->city,
                'website' => $faker->url,
                'phone' => $faker->phoneNumber,
                'stripe_connect_id' => null,
                'stripe_customer_id' => null,
                'completed_onboarding' => $faker->numberBetween(0, 1),
                'image' => "https://randomuser.me/api/portraits/" . (rand(0, 1) ? 'men' : 'women') . "/" . rand(1, 99) . ".jpg",
            ]);
        } catch (\Exception $e) {
            dd("Error on Business Owner create:", $e->getMessage(), $e->getTraceAsString());
        }

        try {
            // 2. Investor (user_type_id = 2)
            $investor = User::updateOrCreate([
                'email' => 'pep.guardiola@gmail.com',
            ], [
                'first_name' => 'Pep',
                'last_name' => 'Guardiola',
                'gender' => 'M',
                'dob' => $faker->date('Y-m-d'),
                'user_type_id' => 2,
                'email' => 'pep.guardiola@gmail.com',
                'email_verified_at' => now(),
                'password' => Hash::make('11111111'),
                'country' => $faker->countryCode,
                'city' => $faker->city,
                'website' => $faker->url,
                'phone' => $faker->phoneNumber,
                'stripe_connect_id' => null,
                'stripe_customer_id' => null,
                'completed_onboarding' => $faker->numberBetween(0, 1),
                'image' => "https://randomuser.me/api/portraits/" . (rand(0, 1) ? 'men' : 'women') . "/" . rand(1, 99) . ".jpg",
            ]);

            // Create investor profile with investment details
            $investor->investor_profile()->create([
                'inv_range' => ['10000-100000', '100000-250000'],
                'turnover_range' => ['100000-500000', '500000-1000000'],
                'interested_sectors' => ['Education', 'Technology', 'Agriculture'],
                'stage' => ['Idea', 'Seed', 'Growth'],
                'social_impact_areas' => ['Education', 'Gender-led', 'Youth-led'],
                'regions_focus' => [$faker->country, $faker->country],
                'past_investment' => $faker->sentence,
            ]);
        } catch (\Exception $e) {
            dd("Error on Investor create:", $e->getMessage(), $e->getTraceAsString());
        }

        try {
            // 3. Service Provider (user_type_id = 3)
            User::updateOrCreate([
                'email' => 'mikel.arteta@gmail.com',
            ], [
                'first_name' => 'Mikel',
                'last_name' => 'Arteta',
                'gender' => 'M',
                'dob' => $faker->date('Y-m-d'),
                'user_type_id' => 3,
                'email' => 'mikel.arteta@gmail.com',
                'email_verified_at' => now(),
                'password' => Hash::make('11111111'),
                'country' => $faker->countryCode,
                'city' => $faker->city,
                'website' => $faker->url,
                'phone' => $faker->phoneNumber,
                'stripe_connect_id' => null,
                'stripe_customer_id' => null,
                'completed_onboarding' => $faker->numberBetween(0, 1),
                'image' => "https://randomuser.me/api/portraits/" . (rand(0, 1) ? 'men' : 'women') . "/" . rand(1, 99) . ".jpg",
            ]);
        } catch (\Exception $e) {
            dd("Error on Service Provider create:", $e->getMessage(), $e->getTraceAsString());
        }

        try {
            // 4. Organization Owner (user_type_id = 4)
            User::updateOrCreate([
                'email' => 'viva.malan166@gmail.com',
            ], [
                'first_name' => 'Viva',
                'last_name' => 'Malan',
                'gender' => 'M',
                'dob' => $faker->date('Y-m-d'),
                'user_type_id' => 4,
                'email' => 'viva.malan166@gmail.com',
                'email_verified_at' => now(),
                'password' => Hash::make('11111111'),
                'country' => $faker->countryCode,
                'city' => $faker->city,
                'website' => $faker->url,
                'phone' => $faker->phoneNumber,
                'stripe_connect_id' => null,
                'stripe_customer_id' => null,
                'completed_onboarding' => $faker->numberBetween(0, 1),
                'image' => "https://randomuser.me/api/portraits/" . (rand(0, 1) ? 'men' : 'women') . "/" . rand(1, 99) . ".jpg",
            ]);
        } catch (\Exception $e) {
            dd("Error on Organization Owner create:", $e->getMessage(), $e->getTraceAsString());
        }

        try {
            // 5. Admin (user_type_id = 5)
            User::updateOrCreate([
                'email' => 'admin@tujitume.com',
            ], [
                'first_name' => $faker->firstName,
                'last_name' => $faker->lastName,
                'gender' => $faker->randomElement(['M', 'F']),
                'dob' => $faker->date('Y-m-d'),
                'user_type_id' => 5,
                'email' => 'admin@tujitume.com',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'country' => $faker->countryCode,
                'city' => $faker->city,
                'website' => $faker->url,
                'phone' => $faker->phoneNumber,
                'stripe_connect_id' => null,
                'stripe_customer_id' => null,
                'completed_onboarding' => 1,
                'image' => "https://randomuser.me/api/portraits/" . (rand(0, 1) ? 'men' : 'women') . "/" . rand(1, 99) . ".jpg",
            ]);
        } catch (\Exception $e) {
            dd("Error on Admin create:", $e->getMessage(), $e->getTraceAsString());
        }

        try {
            // 6. External Reviewer (user_type_id = 6)
            User::updateOrCreate([
                'email' => 'reviewer@tujitume.com',
            ], [
                'first_name' => $faker->firstName,
                'last_name' => $faker->lastName,
                'gender' => $faker->randomElement(['M', 'F']),
                'dob' => $faker->date('Y-m-d'),
                'user_type_id' => 6,
                'email' => 'reviewer@tujitume.com',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'country' => $faker->countryCode,
                'city' => $faker->city,
                'website' => $faker->url,
                'phone' => $faker->phoneNumber,
                'stripe_connect_id' => null,
                'stripe_customer_id' => null,
                'completed_onboarding' => 1,
                'image' => "https://randomuser.me/api/portraits/" . (rand(0, 1) ? 'men' : 'women') . "/" . rand(1, 99) . ".jpg",
            ]);
        } catch (\Exception $e) {
            dd("Error on Reviewer create:", $e->getMessage(), $e->getTraceAsString());
        }

        // Insert additional random users
        try {
            for ($i = 0; $i < 3; $i++) {
                $user = User::updateOrCreate([
                    'email' => $faker->unique()->safeEmail,
                ], [
                    'first_name' => $faker->firstName,
                    'last_name' => $faker->lastName,
                    'gender' => $faker->randomElement(['M', 'F']),
                    'dob' => $faker->date('Y-m-d'),
                    'user_type_id' => $faker->numberBetween(1, 3),
                    'email_verified_at' => now(),
                    'password' => Hash::make('password'),
                    'country' => $faker->countryCode,
                    'city' => $faker->city,
                    'website' => $faker->url,
                    'phone' => $faker->phoneNumber,
                    'stripe_connect_id' => null,
                    'stripe_customer_id' => null,
                    'completed_onboarding' => $faker->numberBetween(0, 1),
                    'image' => "https://randomuser.me/api/portraits/" . (rand(0, 1) ? 'men' : 'women') . "/" . rand(1, 99) . ".jpg",
                ]);

                // If it's an investor, create investor profile
                if ($user->user_type_id === 2) {
                    $user->investor_profile()->updateOrCreate([
                        'user_id' => $user->id,
                    ], [
                        'inv_range' => [$faker->randomElement(['10000-100000', '100000-250000', '250000-500000'])],
                        'turnover_range' => [$faker->randomElement(['100000-500000', '500000-1000000', '1000000+'])],
                        'interested_sectors' => $faker->randomElements(['Education', 'Technology', 'Agriculture', 'Health', 'Energy'], 2),
                        'stage' => $faker->randomElements(['Idea', 'Seed', 'Growth', 'Series A'], 2),
                        'social_impact_areas' => $faker->randomElements(['Education', 'Gender-led', 'Youth-led', 'Environment'], 2),
                        'regions_focus' => [$faker->country, $faker->country],
                        'past_investment' => $faker->sentence,
                    ]);
                }
            }
        } catch (\Exception $e) {
            dd("Error on Random users create:", $e->getMessage(), $e->getTraceAsString());
        }
    }
}
