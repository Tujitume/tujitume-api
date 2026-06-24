<?php

namespace Database\Seeders;

use App\Models\Business\Categories;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Agriculture', 'value' => 'agriculture'],
            ['name' => 'Arts/Culture', 'value' => 'arts_culture'],
            ['name' => 'Auto', 'value' => 'auto'],
            ['name' => 'Domestic (Home Help etc)', 'value' => 'domestic_home_help_etc'],
            ['name' => 'Entertainment', 'value' => 'entertainment'],
            ['name' => 'Fashion', 'value' => 'fashion'],
            ['name' => 'Finance/Accounting', 'value' => 'finance_accounting'],
            ['name' => 'Food', 'value' => 'food'],
            ['name' => 'Healthcare', 'value' => 'healthcare'],
            ['name' => 'Legal', 'value' => 'legal'],
            ['name' => 'Media/Internet', 'value' => 'media_internet'],
            ['name' => 'Other', 'value' => 'other'],
            ['name' => 'Pets', 'value' => 'pets'],
            ['name' => 'Real State', 'value' => 'real_state'],
            ['name' => 'Retail', 'value' => 'retail'],
            ['name' => 'Renewable-Energy', 'value' => 'renewable_energy'],
            ['name' => 'Security', 'value' => 'security'],
            ['name' => 'Sports & Gaming', 'value' => 'sports_gaming'],
            ['name' => 'Technology/Communications', 'value' => 'technology_communications'],
            ['name' => 'Transport', 'value' => 'transport'],
        ];

        Categories::truncate();
        Categories::insert($categories);
//        Categories::where('name', 'Sports/Gaming')
//            ->update(['name' => 'Sports & Gaming']);
    }
}
