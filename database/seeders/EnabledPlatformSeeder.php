<?php

namespace Database\Seeders;

use App\Models\EnabledPlatform;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class EnabledPlatformSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $platforms = [
            [
                'name' => 'Google',
                'slug' => 'google',
                'description' => 'Google Ads platform',
                'is_enabled' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Facebook',
                'slug' => 'facebook',
                'description' => 'Facebook/Meta Ads platform',
                'is_enabled' => true,
                'sort_order' => 2,
            ],
        ];

        foreach ($platforms as $platform) {
            EnabledPlatform::updateOrCreate(
                ['slug' => $platform['slug']],
                $platform
            );
        }
    }
}
