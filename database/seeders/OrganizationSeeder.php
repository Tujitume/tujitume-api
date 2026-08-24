<?php

namespace Database\Seeders;

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Organizations\Workspace;
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
                'image' => 'https://randomuser.me/api/portraits/women/'.rand(1, 99).'.jpg',
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
                'program_industry_id' => 8,
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

            $ngo_owner->update(['organization_id' => $ngo->id]);
            OrganizationUserRole::updateOrCreate(
                ['organization_id' => $ngo->id, 'user_id' => $ngo_owner->id],
                ['role_id' => Role::where('name', 'super_admin')->value('id')]
            );
        } catch (\Exception $e) {
            dd('Error creating NGO Organization:', $e->getMessage(), $e->getTraceAsString());
        }
    }
}
