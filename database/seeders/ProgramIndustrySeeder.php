<?php

namespace Database\Seeders;

use App\Models\ProgramIndustry;
use Illuminate\Database\Seeder;

class ProgramIndustrySeeder extends Seeder
{
    public function run(): void
    {
        $industries = [
            ['id' => 1,  'name' => 'Grants'],
            ['id' => 2,  'name' => 'SACCO Housing'],
            ['id' => 3,  'name' => 'Diaspora Construction'],
            ['id' => 4,  'name' => 'SME Finance'],
            ['id' => 5,  'name' => 'Impact Investment'],
            ['id' => 6,  'name' => 'Accelerator & Incubator Programs'],
            ['id' => 7,  'name' => 'Government & Public Programs'],
            ['id' => 8,  'name' => 'Development Programs'],
            ['id' => 9,  'name' => 'Housing & Real Estate'],
            ['id' => 10, 'name' => 'Construction & Project Delivery'],
            ['id' => 11, 'name' => 'Cooperative Programs'],
            ['id' => 12, 'name' => 'Community Development'],
            ['id' => 13, 'name' => 'Corporate CSR & ESG Programs'],
            ['id' => 14, 'name' => 'Other'],
        ];

        foreach ($industries as $industry) {
            ProgramIndustry::updateOrCreate(
                ['id' => $industry['id']],
                ['name' => $industry['name']]
            );
        }
    }
}
