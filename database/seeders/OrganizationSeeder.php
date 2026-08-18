<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Organizations\workspace;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class OrganizationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        try {
            // Create NGO Organization Owner
            $ngo_owner = User::updateOrCreate([
                'email' => 'john.ngo@tujitume.com',
            ], [
                'first_name' => 'Agnes',
                'last_name' => 'Kipchoge',
                'gender' => 'F',
                'dob' => $faker->date('Y-m-d'),
                'user_type_id' => 4, // organization type
                'email' => 'john.ngo@tujitume.com',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'country' => 'KE',
                'city' => 'Nairobi',
                'phone' => $faker->phoneNumber,
                'website' => 'https://ngo-education.org',
                'stripe_connect_id' => null,
                'stripe_customer_id' => null,
                'completed_onboarding' => 1,
                'image' => "https://randomuser.me/api/portraits/women/" . rand(1, 99) . ".jpg",
            ]);

            // Create Organization for NGO
            $ngo = Organization::updateOrCreate([
                'owner_user_id' => $ngo_owner->id,
            ], [
                'name' => 'Education for All Foundation',
                'display_name' => 'EFA',
                'legal_name' => 'Education for All Foundation Ltd',
                'organization_type' => 'ngo',
                'year_established' => 2015,
                'description' => 'Non-profit organization focused on improving education access in Africa.',
                'email' => 'contact@efa.org',
                'phone' => $faker->phoneNumber,
                'website' => 'https://ngo-education.org',
                'country' => 'KE',
                'region' => 'East Africa',
                'city' => 'Nairobi',
                'primary_industry' => 'Education',
                'focus_sectors' => ['Education', 'Youth Development'],
                'operating_countries' => ['Kenya', 'Uganda', 'Tanzania'],
                'target_regions' => ['East Africa', 'Sub-Saharan Africa'],
                'status' => 'active',
            ]);

            // Create workspace for NGO
            workspace::updateOrCreate([
                'organization_id' => $ngo->id,
                'name' => 'EFA Main Workspace',
                'slug' => 'efa-main2',
                'subdomain' => 'efa',
                'workspace_status' => 'active',
            ]);
        } catch (\Exception $e) {
            dd("Error creating NGO Organization:", $e->getMessage(), $e->getTraceAsString());
        }

        try {
            // Create Foundation Organization Owner
            $foundation_owner = User::updateOrCreate([
                'email' => 'john.foundation@tujitume.com',
            ], [
                'first_name' => 'John',
                'last_name' => 'Smith',
                'gender' => 'M',
                'dob' => $faker->date('Y-m-d'),
                'user_type_id' => 4, // organization type
                'email' => 'john.foundation@tujitume.com',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'country' => 'US',
                'city' => 'New York',
                'phone' => $faker->phoneNumber,
                'website' => 'https://innovation-foundation.org',
                'stripe_connect_id' => null,
                'stripe_customer_id' => null,
                'completed_onboarding' => 1,
                'image' => "https://randomuser.me/api/portraits/men/" . rand(1, 99) . ".jpg",
            ]);

            // Create Organization for Foundation
            $foundation = Organization::updateOrCreate([
                'owner_user_id' => $foundation_owner->id,
            ], [
                'name' => 'Innovation & Technology Foundation',
                'display_name' => 'ITF',
                'legal_name' => 'Innovation & Technology Foundation Inc',
                'organization_type' => 'foundation',
                'year_established' => 2010,
                'description' => 'Foundation supporting tech innovation and entrepreneurship across Africa.',
                'email' => 'contact@itf.org',
                'phone' => $faker->phoneNumber,
                'website' => 'https://innovation-foundation.org',
                'country' => 'US',
                'region' => 'North America',
                'city' => 'New York',
                'primary_industry' => 'Technology',
                'focus_sectors' => ['Technology', 'Innovation', 'Entrepreneurship'],
                'operating_countries' => ['Kenya', 'Nigeria', 'South Africa'],
                'target_regions' => ['Africa', 'Emerging Markets'],
                'status' => 'active',
            ]);

            // Create workspace for Foundation
            workspace::updateOrCreate([
                'organization_id' => $foundation->id,
            ], [
                'name' => 'ITF Main Workspace',
                'slug' => 'itf-main',
                'subdomain' => 'itf',
                'workspace_status' => 'active',
            ]);
        } catch (\Exception $e) {
            dd("Error creating Foundation Organization:", $e->getMessage(), $e->getTraceAsString());
        }

    }
}
