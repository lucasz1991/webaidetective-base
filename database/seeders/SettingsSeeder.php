<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            [
                'type' => 'base',
                'key' => 'company_name',
                'value' => 'cbw-weiterbildung',
            ],
            [
                'type' => 'base',
                'key' => 'contact_email',
                'value' => 'test@cbw-weiterbildung.de',
            ],
            [
                'type' => 'base',
                'key' => 'app_url',
                'value' => 'https://cbw-weiterbildung-schulnetz.shopspaze.com',
            ],
            [
                'type' => 'base',
                'key' => 'currency',
                'value' => 'euro',
            ],
            [
                'type' => 'base',
                'key' => 'maintenance_mode',
                'value' => false,
            ],
            [
                'type' => 'api-grapejs',
                'key' => 'grapejs',
                'value' => 'a15cafec95f0407b8d6ed899618f792c8a45f41b505c4736a22acb54236e8b15',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                [
                    'type' => $setting['type'],
                    'key' => $setting['key'],
                ],
                [
                    'value' => $setting['value'],
                ]
            );
        }
    }
}
