<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Setting::set(
            'deployment_enabled',
            false,
            'boolean',
            'Enable or disable campaign deployment to advertising platforms'
        );

        Setting::set(
            'managed_billing_enabled',
            true,
            'boolean',
            'When enabled, users must pay ad spend through our platform before deploying. When disabled, campaigns deploy directly to the user\'s own ad account.'
        );
    }
}
