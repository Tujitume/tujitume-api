<?php

namespace Database\Seeders;

use App\Models\Misc\Setting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlatformSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultSetting = [
            ['key' => 'vat', 'value' => 2.00],
            ['key' => 'tujitume_fee', 'value' => 5.00],
            ['key' => 'platform_lipr_wallet', 'value' => 'Txuehls73y43fb7373y4rhdrf'],
        ];

        Setting::truncate();
        
        foreach ($defaultSetting as $setting) {
            Setting::query()->insert($setting);
        }
    }
}
