<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Strategy>
 */
class StrategyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'platform' => $this->faker->randomElement(['Google Ads', 'Facebook Ads']),
            'ad_copy_strategy' => $this->faker->paragraph(),
            'imagery_strategy' => $this->faker->paragraph(),
            'video_strategy' => $this->faker->paragraph(),
            'status' => 'pending_approval',
        ];
    }
}
