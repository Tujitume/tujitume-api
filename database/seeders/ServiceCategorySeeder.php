<?php

namespace Database\Seeders;

use App\Models\Services\ServiceCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ServiceCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Business Planning', 'value' => 'business_planning'],
            ['name' => 'IT', 'value' => 'it'],
            ['name' => 'Legal', 'value' => 'legal'],
            ['name' => 'Project Management', 'value' => 'project_management'],
            ['name' => 'Branding and Design', 'value' => 'branding_and_design'],
            ['name' => 'Finance, Accounting & Tax', 'value' => 'finance_accounting_tax'],
            ['name' => 'Marketing', 'value' => 'marketing'],
            ['name' => 'Public Relations', 'value' => 'public_relations'],
            ['name' => 'Other', 'value' => 'other'],
        ];

        ServiceCategory::truncate();
        ServiceCategory::insert($categories);

    }
}
