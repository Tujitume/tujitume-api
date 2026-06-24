<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use App\Models\Capital\CapitalProfile;
use App\Models\Grants\GrantProfile;
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
        $image = "https://randomuser.me/api/portraits/" . (rand(0, 1) ? 'men' : 'men') . "/" . rand(1, 99) . ".jpg";

        try {
            // Insert the business user
            User::create([
                'fname' => 'Harry',
                'mname' => 'J',
                'lname' => 'Kane',
                'gender' => 'M',
                'dob' => $faker->date('Y-m-d'),
                'user_type_id' => 4,
                'email' => 'tottenham266@gmail.com',
                'email_verified_at' => now(),
                'password' => Hash::make('11111111'),
                'id_passport' => strtoupper($faker->bothify('??######')),
                'pin' => $faker->randomNumber(6),
                'inv_range' => null, //$faker->word,
                'turnover_range' => null, //$faker->word,
                'interested_cats' => null, //$faker->word,
                'past_investment' => null,
                'stage' => ["Idea", "Seed"],
                'social_impact_areas' => ["Gender-led", "Youth-led"],
                //'stage' => null,
                //'social_impact_areas' => null,
                'regions_focus' => [$faker->country(), $faker->country()],
                'website' => $faker->url,
                'phone' => $faker->phoneNumber,
                'id_no' => $faker->uuid,
                'tax_pin' => strtoupper($faker->bothify('PIN-###??')),
                'connect_id' => 'acct_1Nue6WQtwNiZ4yJ0', //acct_1NcUipR0zSac5ey4 $faker->uuid,
                'token' => '1778118179790478',  //1776943631370498 $faker->sha256,
                'completed_onboarding' => $faker->numberBetween(0, 1),
                'remember_token' => $faker->sha1,
                'image' => "https://randomuser.me/api/portraits/" . (rand(0, 1) ? 'men' : 'men') . "/" . rand(1, 99) . ".jpg",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            dd("Error on Harry Kane create:", $e->getMessage(), $e->getTraceAsString());
        }

        try {
            // Insert the grant user
            $grant_user = User::create([
                'fname' => 'Pep',
                'mname' => 'J',
                'lname' => 'Guardiola',
                'gender' => 'M',
                'dob' => $faker->date('Y-m-d'),
                'user_type_id' => 2,
                'email' => 'test_grant@gmail.com',
                'email_verified_at' => now(),
                'password' => Hash::make('11111111'),
                'id_passport' => strtoupper($faker->bothify('??######')),
                'pin' => $faker->randomNumber(6),
                'inv_range' => null, //$faker->word,
                'turnover_range' => null, //$faker->word,
                'interested_cats' => null, //$faker->word,
                'past_investment' => $faker->sentence,
                'stage' => ["Idea", "Seed"],
                'social_impact_areas' => [$faker->word, $faker->word],
                'regions_focus' => [$faker->country(), $faker->country()],
                'website' => $faker->url,
                'phone' => $faker->phoneNumber,
                'id_no' => $faker->uuid,
                'tax_pin' => strtoupper($faker->bothify('PIN-###??')),
                'connect_id' => $faker->uuid,
                'token' => $faker->sha256,
                'completed_onboarding' => $faker->numberBetween(0, 1),
                'remember_token' => $faker->sha1,
                'image' => $image,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            GrantProfile::create([
                'user_id' => $grant_user->id,
                'role_id' => null,
                'grant_owner_id' => null,
                'org_type' => 'Non-Profit',
                'regions' => 'Africa,Asia,Kenya,Uganda',
                'mission' => 'To improve education access globally.',
                'document' => 'mission_doc_1.pdf',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        catch (\Exception $e) {
            dd("Error on Guardiola create:", $e->getMessage(), $e->getLine(), $e->getTraceAsString());
        }


        try{
            // Insert the capital user
            $cap_user = User::create([
                'fname' => 'Mikel',
                'mname' => 'J',
                'lname' => 'Arteta',
                'gender' => 'M',
                'dob' => $faker->date('Y-m-d'),
                'user_type_id' => 3,
                'email' => 'test_capital@gmail.com',
                'email_verified_at' => now(),
                'password' => Hash::make('11111111'),
                'id_passport' => strtoupper($faker->bothify('??######')),
                'pin' => $faker->randomNumber(6),
                'inv_range' => null, //$faker->word,
                'turnover_range' => null, //$faker->word,
                'interested_cats' => null, //$faker->word,
                'past_investment' => $faker->sentence,
                'stage' => [$faker->word, $faker->word],
                'social_impact_areas' => [$faker->word, $faker->word],
                'regions_focus' => [$faker->country(), $faker->country()],
                'website' => $faker->url,
                'phone' => $faker->phoneNumber,
                'id_no' => $faker->uuid,
                'tax_pin' => strtoupper($faker->bothify('PIN-###??')),
                'connect_id' => $faker->uuid,
                'token' => $faker->sha256,
                'completed_onboarding' => $faker->numberBetween(0, 1),
                'remember_token' => $faker->sha1,
                'image' => $image,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            CapitalProfile::create([
                'user_id' => $cap_user->id,
                'role_id' => null,
                'capital_owner_id' => null,
                'org_type' => 'Non-Profit',
                'regions' => 'Africa,Asia,Kenya',
                'startup_stage' => 'idea,early,growth',
                'eng_prefer' => 'To improve education access globally.',
                'document' => 'mission_doc_1.pdf',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        catch (\Exception $e) {
            dd("Error on Mikel Arteta create:", $e->getMessage(), $e->getTraceAsString());
        }

        try{
            // Insert the investor user
            User::create([
                'fname' => 'Viva',
                'mname' => 'A',
                'lname' => 'Malan',
                'gender' => 'M',
                'dob' => $faker->date('Y-m-d'),
                'user_type_id' => 1,
                'email' => 'viva.malan166@gmail.com',
                'email_verified_at' => now(),
                'password' => Hash::make('11111111'),
                'id_passport' => strtoupper($faker->bothify('??######')),
                'pin' => $faker->randomNumber(6),

                'inv_range' => ['10000-100000', '100000-250000'],
                'turnover_range' => ['10000-100000', '100000-250000'],
                'interested_cats' => ['Education', 'Technology', 'Sustainability'],
                'stage' => ['Idea', 'Seed', 'Growth'],
                'social_impact_areas' => ['Education', 'Gender-led', 'Youth-led'],
                'regions_focus' => [
                    $faker->country,
                    $faker->country
                ],

                'past_investment' => $faker->sentence,
                'website' => $faker->url,
                'phone' => $faker->phoneNumber,
                'id_no' => $faker->uuid,
                'tax_pin' => strtoupper($faker->bothify('PIN-###??')),
                'connect_id' => $faker->uuid,
                'token' => $faker->sha256,
                'completed_onboarding' => $faker->numberBetween(0, 1),
                'remember_token' => $faker->sha1,
                'image' => "https://randomuser.me/api/portraits/" . (rand(0, 1) ? 'men' : 'men') . "/" . rand(1, 99) . ".jpg",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        catch (\Exception $e) {
            dd("Error on Viva create:", $e->getMessage(), $e->getTraceAsString());
        }

        try{
            //Service Owner
            User::create([
                'fname' => $faker->firstName,
                'mname' => $faker->firstName,
                'lname' => $faker->lastName,
                'gender' => $faker->randomElement(['M', 'F']),
                'dob' => $faker->date('Y-m-d'),
                'user_type_id' => 5,
                'email' => 'tronrane266@gmail.com',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'id_passport' => strtoupper($faker->bothify('??######')),
                'pin' => $faker->randomNumber(6),
                'inv_range' => null,
                'turnover_range' => null,
                'interested_cats' => null,
                'past_investment' => 'Past records are good!',
                'stage' => null,
                'social_impact_areas' => null,
                'regions_focus' => null,
                'website' => $faker->url,
                'phone' => $faker->phoneNumber,
                'id_no' => $faker->uuid,
                'tax_pin' => strtoupper($faker->bothify('PIN-###??')),
                'connect_id' => $faker->uuid,
                'token' => $faker->sha256,
                'completed_onboarding' => $faker->numberBetween(0, 1),
                'remember_token' => $faker->sha1,
                'image' => $image,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Insert 5 more random users
            for ($i = 0; $i < 3; $i++) {
                User::create([
                    'fname' => $faker->firstName,
                    'mname' => $faker->firstName,
                    'lname' => $faker->lastName,
                    'gender' => $faker->randomElement(['M', 'F']),
                    'dob' => $faker->date('Y-m-d'),
                    'user_type_id' => $faker->numberBetween(2, 5),
                    'email' => $faker->unique()->safeEmail,
                    'email_verified_at' => now(),
                    'password' => Hash::make('password'),
                    'id_passport' => strtoupper($faker->bothify('??######')),
                    'pin' => $faker->randomNumber(6),
                    'inv_range' => null,
                    'turnover_range' => null,
                    'interested_cats' => null,
                    'past_investment' => 'Past records are good!',
                    'stage' => null,
                    'social_impact_areas' => null,
                    'regions_focus' => null,
                    'website' => $faker->url,
                    'phone' => $faker->phoneNumber,
                    'id_no' => $faker->uuid,
                    'tax_pin' => strtoupper($faker->bothify('PIN-###??')),
                    'connect_id' => $faker->uuid,
                    'token' => $faker->sha256,
                    'completed_onboarding' => $faker->numberBetween(0, 1),
                    'remember_token' => $faker->sha1,
                    'image' => "https://randomuser.me/api/portraits/" . (rand(0, 1) ? 'men' : 'men') . "/" . rand(1, 99) . ".jpg",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Exception $e) {
            dd("Error on Random create:", $e->getMessage(), $e->getTraceAsString());
        }
    }
}
