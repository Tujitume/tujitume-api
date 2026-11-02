<?php

namespace Database\Seeders;

use App\Models\Programs\ProgramMetadata;
use Illuminate\Database\Seeder;

class ProgramMetadataSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'startup_stage_focus' => ['idea', 'pre_seed', 'seed', 'early_stage', 'growth', 'expansion', 'mature', 'series_a', 'series_b', 'series_c_plus'],
            'social_impact_areas' => ['food_security', 'carbon_reduction', 'job_creation', 'water_conservation', 'education', 'financial_inclusion', 'healthcare', 'gender_equality', 'youth_empowerment', 'rural_development'],
            'bonus_points' => ['gender_led', 'youth_led', 'rural_based', 'uses_local_sourcing'],
        ];

        foreach ($defaults as $key => $value) {
            ProgramMetadata::updateOrCreate(['program_industry_id' => null, 'key' => $key], ['value' => $value]);
        }
    }
}
