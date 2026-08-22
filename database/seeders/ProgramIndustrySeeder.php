<?php

namespace Database\Seeders;

use App\Models\ProgramIndustry;
use Illuminate\Database\Seeder;

class ProgramIndustrySeeder extends Seeder
{
    public function run(): void
    {
        $industries = [
            ['id' => 1,  'name' => 'Grants', 'url' => 'grants'],
            ['id' => 2,  'name' => 'SACCO Housing', 'url' => 'sacco-housing'],
            ['id' => 3,  'name' => 'Diaspora Construction', 'url' => 'diaspora-construction'],
            ['id' => 4,  'name' => 'SME Finance', 'url' => 'sme-finance'],
            ['id' => 5,  'name' => 'Impact Investment', 'url' => 'impact-investment'],
            ['id' => 6,  'name' => 'Accelerator & Incubator Programs', 'url' => 'accelerator-incubator-programs'],
            ['id' => 7,  'name' => 'Government & Public Programs', 'url' => 'government-public-programs'],
            ['id' => 8,  'name' => 'Development Programs', 'url' => 'development-programs'],
            ['id' => 9,  'name' => 'Housing & Real Estate', 'url' => 'housing-real-estate'],
            ['id' => 10, 'name' => 'Construction & Project Delivery', 'url' => 'construction-project-delivery'],
            ['id' => 11, 'name' => 'Cooperative Programs', 'url' => 'cooperative-programs'],
            ['id' => 12, 'name' => 'Community Development', 'url' => 'community-development'],
            ['id' => 13, 'name' => 'Corporate CSR & ESG Programs', 'url' => 'corporate-csr-esg-programs'],
            ['id' => 14, 'name' => 'Other', 'url' => 'other'],
        ];

        foreach ($industries as $industry) {
            ProgramIndustry::updateOrCreate(
                ['id' => $industry['id']],
                [
                    'name' => $industry['name'],
                    'url' => $industry['url'],
                ]
            );
        }
    }
}
